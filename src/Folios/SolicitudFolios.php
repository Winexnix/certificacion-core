<?php

namespace Winex\Certificacion\Folios;

use Winex\Certificacion\Core\Certificate;

/**
 * Pide folios (CAF) al SII replicando el asistente web legacy `cvc_cgi/dte/of_*`.
 * Ambiente::Produccion usa palena; Ambiente::Certificacion usa maullin.
 *
 * Login por certificado cliente TLS contra AUT2000 (`herculesr.sii.cl`, igual en
 * los dos ambientes: solo cambia la URL de vuelta). Despues se navegan los forms
 * del asistente parseando el HTML de cada paso:
 *   1. GET  of_solicita_folios          -> form "RUT del contribuyente"
 *   2. POST of_solicita_folios_dcto     -> tipo de documento + cantidad
 *   3. POST of_confirma_folio           -> el SII fija NOMUSU / FOLIO_INI / FOLIO_FIN
 *   4. POST of_genera_folio             -> * TIMBRA los folios (irreversible) *
 *   5. POST of_genera_archivo           -> descarga el XML del CAF
 *
 * Candados del ambiente (no dependen de configuracion):
 *   - toda peticion del asistente, y cada redireccion, tiene que ir al host del
 *     ambiente; los `action` los manda el SII y se revisan antes de enviar;
 *   - el CAF descargado tiene que traer el IDK del ambiente, el RUT de la
 *     empresa y el tipo pedido.
 */
final class SolicitudFolios
{
    private const AUTH = 'https://herculesr.sii.cl';

    private const SESSION = 'https://zeusr.sii.cl';

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';

    private const SEGUNDOS_LOGIN = 45;

    private const SEGUNDOS_ASISTENTE = 70;

    private const MAX_REDIRECCIONES = 10;

    /** Textos que delatan que el SII devolvio (o redirigio a) el login. */
    private const MARCADORES_LOGIN = ['autInicioDTE', 'IngresoCertificado', 'InicioAutenticacion', 'CAutInicio.cgi', 'CAutValida'];

    /** Textos que delatan una pagina de error del SII. */
    private const MARCADORES_ERROR = [
        'El c&oacute;digo de este mensaje es',
        'El código de este mensaje es',
        'no se encuentra autorizado',
        'no est&aacute; autorizado',
        'no está autorizado',
        'Usted no tiene',
        'no es representante',
        'excede',
        'no puede solicitar',
    ];

    private TransporteHttp $transporte;

    public function __construct(
        private readonly Ambiente $ambiente,
        ?TransporteHttp $transporte = null,
    ) {
        $this->transporte = $transporte ?? new TransporteCurl;
    }

    public function ambiente(): Ambiente
    {
        return $this->ambiente;
    }

    /**
     * Login AUT2000 con el certificado. El RUT del certificado tiene que ser
     * representante electronico de la empresa para la que despues se piden folios.
     *
     * Ojo: el SII frena el re-login seguido (~5 ciclos -> error 01.01.215.500.771.52).
     * Si se piden folios a menudo, guardar la sesion (cifrada) y reusarla.
     *
     * @throws SolicitudFoliosException
     */
    public function autenticar(Certificate $cert): SesionSii
    {
        $sesion = new SesionSii;
        $referencia = $this->ambiente->portal().'/cvc_cgi/dte/of_solicita_folios';

        $respuesta = $this->pedir(
            $sesion,
            'POST',
            self::AUTH."/cgi_AUT2000/CAutInicio.cgi?{$referencia}",
            ['referencia' => $referencia],
            [
                'Origin' => self::SESSION,
                'Referer' => self::SESSION."/AUT2000/InicioAutenticacion/IngresoCertificado.html?{$referencia}",
            ],
            // Cadena completa (hoja + intermedios): sin ella el SII corta el
            // handshake con "tlsv1 alert unknown ca" para algunos proveedores.
            ['cert' => $cert->getPublicKeyChain(), 'key' => $cert->getPrivateKey()],
            self::SEGUNDOS_LOGIN,
            // El login vive en otros hosts del SII; ahi no se timbra nada. Solo
            // se exige que no salga de sii.cl.
            fn (string $url) => $this->soloSii($url),
        );

        $sesion->recibirDesdeHtml($respuesta->cuerpo);

        if (! $sesion->tiene('TOKEN')) {
            throw new SolicitudFoliosException(
                'El SII no acepto el certificado para pedir folios. Revisa que este vigente y que el RUT '
                .'del certificado sea representante electronico de la empresa.',
            );
        }

        return $sesion;
    }

    /**
     * Timbra `$cantidad` folios del tipo `$tipoDte` para `$rutEmpresa` y devuelve
     * el XML del CAF, ya revisado contra el ambiente.
     *
     * @throws SesionCaducaException si la sesion ya no sirve (antes de timbrar)
     * @throws FoliosSinCafException si se timbro pero no se pudo descargar el CAF
     * @throws SolicitudFoliosException
     */
    public function solicitar(SesionSii $sesion, string $rutEmpresa, int $tipoDte, int $cantidad): string
    {
        if ($cantidad < 1) {
            throw new SolicitudFoliosException('La cantidad de folios debe ser al menos 1.');
        }

        [$rut, $dv] = $this->rutDv($rutEmpresa);
        if ($rut === '' || $dv === '') {
            throw new SolicitudFoliosException("RUT de empresa invalido: {$rutEmpresa}.");
        }

        $base = $this->ambiente->portal().'/cvc_cgi/dte';

        // Paso 1.
        $html = $this->asistente($sesion, 'GET', "{$base}/of_solicita_folios", null, "{$base}/of_solicita_folios");
        $this->sesionVigente($html);

        // Paso 2: tipo de documento + cantidad. El asistente puede recargar
        // `of_solicita_folios_dcto` sobre si mismo (primero el tipo, luego la
        // cantidad); se reenvia hasta caer en la confirmacion.
        $overrides = [
            'RUT_EMP' => $rut,
            'DV_EMP' => $dv,
            'FOLIO_INICIAL' => '0',
            'COD_DOCTO' => (string) $tipoDte,
            'CANT_DOCTOS' => (string) $cantidad,
            'ACEPTAR' => 'Continuar',
        ];

        $referer = "{$base}/of_solicita_folios";
        for ($i = 0; $i < 4; $i++) {
            $form = $this->form($html);
            $url = $this->resolver($base, $form['action']);
            $html = $this->asistente($sesion, 'POST', $url, array_merge($form['inputs'], $overrides), $referer);
            $this->sesionVigente($html);
            $this->sinError($html);
            $referer = $url;

            if (str_contains($this->form($html)['action'], 'of_genera_folio')) {
                break;
            }
        }

        // Paso 3: confirmacion. Su form apunta a of_genera_folio y trae los campos
        // que decide el SII.
        $confirma = $this->form($html);
        if (! str_contains($confirma['action'], 'of_genera_folio')) {
            throw new SolicitudFoliosException('El SII no llego a la confirmacion de folios. '.$this->mensajeSii($html));
        }

        $datos = $confirma['inputs'];
        foreach (['FOLIO_INI', 'FOLIO_FIN', 'NOMUSU'] as $campo) {
            if (($datos[$campo] ?? '') === '') {
                throw new SolicitudFoliosException("La confirmacion del SII no trae {$campo}. ".$this->mensajeSii($html));
            }
        }
        $folioIni = $datos['FOLIO_INI'];
        $folioFin = $datos['FOLIO_FIN'];

        // Paso 4: generar. ** Esto timbra los folios. ** El candado se revisa
        // aparte y antes: si corta aca, no se envio nada.
        $urlGenera = $this->resolver($base, $confirma['action']);
        $this->soloAmbiente($urlGenera);

        // Desde que sale el POST los folios pueden estar timbrados: cualquier falla
        // (red incluida) se informa como FoliosSinCafException para que nadie
        // reintente a ciegas.
        $sinCaf = fn (string $detalle = '') => new FoliosSinCafException((int) $folioIni, (int) $folioFin, $this->ambiente, $detalle);

        try {
            $genera = $this->asistente(
                $sesion,
                'POST',
                $urlGenera,
                array_merge($confirma['inputs'], ['ACEPTAR' => 'Obtener Folios']),
                $referer,
            );
            $this->sinError($genera);

            // Paso 5: descargar el XML del CAF.
            $descarga = $this->form($genera);
            $xml = $this->asistente($sesion, 'POST', $this->resolver($base, $descarga['action']), array_merge($descarga['inputs'], [
                'RUT_EMP' => $rut,
                'DV_EMP' => $dv,
                'COD_DOCTO' => (string) $tipoDte,
                'FOLIO_INI' => $folioIni,
                'FOLIO_FIN' => $folioFin,
                // Hora de Chile explicita: el SII usa FECHA como FA del CAF. Con date() en
                // una app en UTC, desde las 21:00 el CAF salia con fecha de manana y cada
                // boleta timbrada hoy quedaba con reparo TED-1-646.
                'FECHA' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago')))->format('Y-m-d'),
                'ACEPTAR' => 'AQUI',
            ]), "{$base}/of_genera_folio", convertir: false);
        } catch (SolicitudFoliosException $e) {
            throw $sinCaf($e->getMessage());
        }

        if (! str_contains($xml, '<AUTORIZACION') || ! str_contains($xml, '<CAF')) {
            throw $sinCaf($this->mensajeSii($xml));
        }

        $this->revisarCaf($xml, "{$rut}-{$dv}", $tipoDte);

        return $xml;
    }

    // --- candados -------------------------------------------------------------

    private function soloAmbiente(string $url): void
    {
        $this->sesionVigente($url);

        $partes = parse_url($url);

        if (($partes['scheme'] ?? '') !== 'https' || strtolower($partes['host'] ?? '') !== $this->ambiente->host()) {
            throw new SolicitudFoliosException(
                "Solicitud de folios detenida: el SII apunto fuera del ambiente de {$this->ambiente->value} ({$this->ambiente->host()}).",
            );
        }
    }

    private function soloSii(string $url): void
    {
        $partes = parse_url($url);
        $host = strtolower($partes['host'] ?? '');

        if (($partes['scheme'] ?? '') !== 'https' || ($host !== 'sii.cl' && ! str_ends_with($host, '.sii.cl'))) {
            throw new SolicitudFoliosException('Autenticacion detenida: el SII redirigio fuera de sii.cl.');
        }
    }

    /** El CAF tiene que ser del ambiente (IDK), de la empresa (RE) y del tipo pedido (TD). */
    private function revisarCaf(string $xml, string $rutEmpresa, int $tipoDte): void
    {
        $contenido = (string) preg_replace('/^\xEF\xBB\xBF/', '', $xml);
        if (! mb_check_encoding($contenido, 'UTF-8')) {
            // Los CAF del SII traen bytes ISO-8859-1 (tildes de la razon social).
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'ISO-8859-1');
            $contenido = (string) preg_replace('/(<\?xml[^>]*encoding=")[^"]*(")/i', '$1UTF-8$2', $contenido, 1);
        }

        $dom = new \DOMDocument;
        $previo = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($contenido);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        if (! $ok) {
            throw new SolicitudFoliosException('El CAF que entrego el SII no es un XML valido. No se uso.');
        }

        $texto = fn (string $tag): string => trim((string) $dom->getElementsByTagName($tag)->item(0)?->nodeValue);
        $normalizar = fn (string $rut): string => strtoupper((string) preg_replace('/[^0-9kK-]/', '', $rut));

        if ($texto('IDK') !== $this->ambiente->idk()) {
            throw new SolicitudFoliosException(
                "El CAF que entrego el SII no es del ambiente de {$this->ambiente->value} (IDK {$texto('IDK')}, se esperaba {$this->ambiente->idk()}). No se uso.",
            );
        }
        if ($normalizar($texto('RE')) !== $normalizar($rutEmpresa)) {
            throw new SolicitudFoliosException("El CAF que entrego el SII es del RUT {$texto('RE')}, no de {$rutEmpresa}. No se uso.");
        }
        if ($texto('TD') !== (string) $tipoDte) {
            throw new SolicitudFoliosException("El CAF que entrego el SII es de tipo {$texto('TD')}, no {$tipoDte}. No se uso.");
        }
    }

    // --- HTTP -------------------------------------------------------------------

    /**
     * Peticion al asistente: la URL y cada redireccion tienen que ser del
     * ambiente. Devuelve el cuerpo (en UTF-8 salvo `$convertir = false`).
     *
     * @param  array<string, string>|null  $formulario
     */
    private function asistente(SesionSii $sesion, string $metodo, string $url, ?array $formulario, string $referer, bool $convertir = true): string
    {
        $cuerpo = $this->pedir(
            $sesion, $metodo, $url, $formulario, ['Referer' => $referer], null, self::SEGUNDOS_ASISTENTE,
            fn (string $destino) => $this->soloAmbiente($destino),
        )->cuerpo;

        if (! $convertir || mb_check_encoding($cuerpo, 'UTF-8')) {
            return $cuerpo;
        }

        return mb_convert_encoding($cuerpo, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Envia y sigue redirecciones a mano. `$revisar` se llama con la URL inicial y
     * con cada destino ANTES de enviarle nada.
     *
     * @param  array<string, string>|null  $formulario
     * @param  array<string, string>  $cabeceras
     * @param  array{cert: string, key: string}|null  $tls
     * @param  callable(string): void  $revisar
     */
    private function pedir(SesionSii $sesion, string $metodo, string $url, ?array $formulario, array $cabeceras, ?array $tls, int $segundos, callable $revisar): RespuestaHttp
    {
        for ($salto = 0; $salto <= self::MAX_REDIRECCIONES; $salto++) {
            $revisar($url);

            $enviar = $cabeceras + ['User-Agent' => self::UA];
            if (($cookie = $sesion->cabeceraPara($url)) !== null) {
                $enviar['Cookie'] = $cookie;
            }

            $respuesta = $this->transporte->enviar($metodo, $url, $enviar, $formulario, $tls, $segundos);
            $sesion->recibir($url, $respuesta);

            if (! $respuesta->esRedireccion()) {
                return $respuesta;
            }

            $anterior = $url;
            $url = $this->resolverUrl($anterior, $respuesta->cabecera('location')[0]);
            $cabeceras['Referer'] = $anterior;

            // Como los navegadores: 301/302/303 tras un POST siguen con GET sin cuerpo.
            if ($respuesta->status !== 307 && $respuesta->status !== 308) {
                $metodo = 'GET';
                $formulario = null;
            }
        }

        throw new SolicitudFoliosException('El SII redirigio demasiadas veces.');
    }

    // --- parseo HTML --------------------------------------------------------------

    /**
     * Primer <form> de la pagina: action y todos sus inputs/selects.
     *
     * @return array{action: string, inputs: array<string, string>}
     */
    private function form(string $html): array
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $form = $doc->getElementsByTagName('form')->item(0);
        if (! $form instanceof \DOMElement) {
            throw new SolicitudFoliosException('El SII no devolvio el formulario esperado. '.$this->mensajeSii($html));
        }

        $inputs = [];

        foreach ($form->getElementsByTagName('input') as $el) {
            $name = $el->getAttribute('name');
            if ($name === '' || in_array(strtolower($el->getAttribute('type')), ['button', 'reset'], true)) {
                continue;
            }
            $inputs[$name] = $el->getAttribute('value');
        }

        foreach ($form->getElementsByTagName('select') as $el) {
            $name = $el->getAttribute('name');
            if ($name === '') {
                continue;
            }
            $valor = '';
            foreach ($el->getElementsByTagName('option') as $opt) {
                if ($valor === '') {
                    $valor = $opt->getAttribute('value');
                }
                if ($opt->hasAttribute('selected')) {
                    $valor = $opt->getAttribute('value');
                    break;
                }
            }
            $inputs[$name] = $valor;
        }

        return ['action' => $form->getAttribute('action'), 'inputs' => $inputs];
    }

    /** `action` de un form del asistente, relativo a `$base`. */
    private function resolver(string $base, string $action): string
    {
        return $action === '' ? $base : $this->resolverUrl("{$base}/", $action);
    }

    /** Resuelve `$ref` (absoluta, //host, /ruta o relativa) contra `$desde`. */
    private function resolverUrl(string $desde, string $ref): string
    {
        $ref = trim($ref);

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $ref)) {
            return $ref;
        }

        $partes = parse_url($desde);
        $esquema = $partes['scheme'] ?? 'https';

        if (str_starts_with($ref, '//')) {
            return "{$esquema}:{$ref}";
        }

        $raiz = "{$esquema}://".($partes['host'] ?? '');

        if (str_starts_with($ref, '/')) {
            return $raiz.$ref;
        }

        $ruta = $partes['path'] ?? '/';
        $dir = substr($ruta, 0, (int) strrpos($ruta, '/') + 1);

        return $raiz.$dir.$ref;
    }

    private function sesionVigente(string $texto): void
    {
        foreach (self::MARCADORES_LOGIN as $marcador) {
            if (str_contains($texto, $marcador)) {
                throw new SesionCaducaException('La sesion del SII caduco; hay que volver a autenticar.');
            }
        }
    }

    private function sinError(string $html): void
    {
        foreach (self::MARCADORES_ERROR as $marcador) {
            if (mb_stripos($html, $marcador) !== false) {
                throw new SolicitudFoliosException('El SII rechazo la solicitud de folios. '.$this->mensajeSii($html));
            }
        }
    }

    private function mensajeSii(string $html): string
    {
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $texto = trim((string) preg_replace('/\s+/', ' ', $texto));

        return $texto === '' ? '' : '(SII: '.mb_substr($texto, 0, 300).')';
    }

    /** @return array{0: string, 1: string} */
    private function rutDv(string $rut): array
    {
        $partes = explode('-', trim($rut));

        return [
            (string) preg_replace('/\D/', '', $partes[0]),
            strtoupper(trim($partes[1] ?? '')),
        ];
    }
}
