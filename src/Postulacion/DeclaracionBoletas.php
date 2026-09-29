<?php

namespace Winex\Certificacion\Postulacion;

use Winex\Certificacion\Folios\SesionCaducaException;
use Winex\Certificacion\Folios\SesionSii;
use Winex\Certificacion\Folios\TransporteCurl;
use Winex\Certificacion\Folios\TransporteCuerpoCrudo;

/**
 * Declaracion de cumplimiento de requisitos de Boleta Electronica: el ultimo paso
 * de la certificacion, que deja a la empresa AUTORIZADA EN PRODUCCION para emitir
 * boletas (39 y, si se postulo, 41).
 *
 * Vive en la app GWT `https://www4.sii.cl/certBolElectDteInternet/` (no en
 * maullin) y se habla por GWT-RPC con la misma sesion AUT2000 de
 * PostulacionSii/SolicitudFolios. Capturado 2026-09-14 (version 1.0.55 del portal).
 *
 * Si el SII publica otra version, las llamadas responden
 * IncompatibleRemoteServiceException y aca se corta con un mensaje claro: hay que
 * actualizar POLICY/PERMUTACION (y revisar los esquemas) desde el portal.
 */
final class DeclaracionBoletas
{
    private const HOST = 'www4.sii.cl';

    private const BASE = 'https://www4.sii.cl/certBolElectDteInternet/';

    private const POLICY = '082D0AC4BC4D75A5DF38F116C53877D4';

    private const PERMUTACION = '0FC3D987613537E6E13E9BB93A406F13';

    private const SERVICIO = 'cl.sii.sdi.diii.certBolElectDte.web.client.service.Facade';

    private const SEGUNDOS = 45;

    /** Paso "declarar cumplimiento" del set de boletas. */
    private const PASO = 90;

    private const ESTADO = 'cl.sii.sdi.diii.certBolElectDte.to.EstadoAutorizaTo/1011533792';

    private const POSTULACION = 'cl.sii.sdi.diii.certBolElectDte.to.PostulSegHistInsUpdTo/138688689';

    private const EMPRESA_AUTORIZADA = 'cl.sii.sdi.diii.certBolElectDte.to.TdtEmpresaAutorizadaTo/2240026086';

    private const DOCUMENTO = 'cl.sii.sdi.diii.certBolElectDte.to.DocumentoAutorizadoTo/493967287';

    /**
     * Orden de serializacion de cada DTO ('S' = campo String, 'O' = objeto,
     * 'B' = boolean), sacado de los FieldSerializer del JavaScript del portal.
     */
    private const ESQUEMAS = [
        self::ESTADO => 'SSS',
        self::POSTULACION => 'SOSOSSOSSSOSSSOSSOOOOS',
        self::EMPRESA_AUTORIZADA => 'BSSSSSSSSSSSSOOOS',
        self::DOCUMENTO => 'OOSSSSSSSSSOOOOOS',
    ];

    private NavegadorSii $navegador;

    public function __construct(?TransporteCuerpoCrudo $transporte = null)
    {
        $this->navegador = new NavegadorSii($transporte ?? new TransporteCurl, self::HOST);
    }

    /**
     * Estado de la certificacion de boletas. `porDeclarar()` = se puede declarar.
     * Una empresa ya autorizada, sin tramite o de la que el usuario no es
     * representante responde sin codigo.
     *
     * @throws SesionCaducaException
     * @throws PostulacionException
     */
    public function estado(SesionSii $sesion, string $rutEmpresa): EstadoDeclaracion
    {
        $empresa = Rut::de($rutEmpresa, 'RUT de la empresa');

        $r = $this->llamar($sesion, 'obtenerEstadoAutorizaEmp', [
            [GwtRpc::INTEGER, fn (GwtRpc $w) => $w->integer((int) $empresa->numero)],
            [GwtRpc::STRING, fn (GwtRpc $w) => $w->string($empresa->dv)],
            [GwtRpc::INTEGER, fn (GwtRpc $w) => $w->integer(self::PASO)],
            [GwtRpc::STRING, fn (GwtRpc $w) => $w->string(null)],
        ])->objeto();

        if ($r === null) {
            return new EstadoDeclaracion($empresa, null, '', null);
        }

        [$codigo, $paso, $glosa] = array_column($r['campos'], 1);

        return new EstadoDeclaracion($empresa, $codigo, (string) $glosa, is_numeric($paso) ? (int) $paso : null);
    }

    /**
     * Graba la declaracion de cumplimiento. ** Autoriza a la empresa en produccion. **
     *
     * Solo sale si el estado es P90. El usuario de la sesion firma la declaracion
     * como representante legal.
     *
     * @throws SesionCaducaException antes de grabar
     * @throws DeclaracionInciertaException si la orden salio y no se pudo confirmar
     * @throws PostulacionException
     */
    public function declarar(SesionSii $sesion, DatosDeclaracion $datos): EstadoDeclaracion
    {
        $empresa = $datos->empresa;
        $usuario = $this->usuario($sesion);

        $estado = $this->estado($sesion, (string) $empresa);
        if (! $estado->porDeclarar()) {
            throw new PostulacionException(
                "La empresa {$empresa} no esta en el paso de declarar cumplimiento de boletas"
                .($estado->codigo !== null ? " (estado {$estado->codigo} {$estado->glosa})" : ' (sin tramite pendiente: ya autorizada, sin postular o el usuario no la representa)')
                .'.',
            );
        }

        $rut = fn (GwtRpc $w) => $w->integer((int) $empresa->numero);

        $razonSocial = $this->llamar($sesion, 'recuperarNombreContribuyente', [
            [GwtRpc::INTEGER, $rut],
            [GwtRpc::STRING, fn (GwtRpc $w) => $w->string($empresa->dv)],
        ])->string();

        $documentos = $this->llamar($sesion, 'listarDocAutorizados', [
            [GwtRpc::INTEGER, $rut],
            [GwtRpc::STRING, fn (GwtRpc $w) => $w->string($empresa->dv)],
            [GwtRpc::STRING, fn (GwtRpc $w) => $w->string((string) ($estado->paso ?? self::PASO))],
        ])->objeto();

        if ($razonSocial === null || $razonSocial === '') {
            throw new PostulacionException("El SII no devolvio la razon social de {$empresa}.");
        }
        if ($documentos === null || ($documentos['elementos'] ?? []) === []) {
            throw new PostulacionException("El SII no tiene documentos por autorizar para {$empresa}.");
        }

        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago')))->format('d-m-Y');

        $cuerpo = $this->cuerpo('autorizarEmpresaBolProd', [[self::POSTULACION, function (GwtRpc $w) use ($datos, $empresa, $usuario, $estado, $razonSocial, $documentos, $hoy) {
            // PostulSegHistInsUpdTo (ver Gi() del portal)
            $w->tipo(self::POSTULACION)
                ->string('P91')                                 // b  estado nuevo
                ->nulo()                                        // c
                ->string('BVE')                                 // d  sistema boleta
                ->integer($estado->paso ?? self::PASO)          // e  paso
                ->nulo()->string(null)->nulo()->string(null)    // f g i j
                ->string($empresa->dv)                          // k
                ->string(null);                                 // n

            // o: TdtEmpresaAutorizadaTo (ver Fi() del portal)
            $w->tipo(self::EMPRESA_AUTORIZADA)
                ->int(0)                                        // b  boolean false
                ->string('BVE')                                 // c
                ->string($empresa->dv)                          // d  dv empresa
                ->string($datos->proveedor->dv)                 // e  dv proveedor
                ->string($usuario->dv)                          // f  dv usuario
                ->string('SII')                                 // g
                ->string($hoy)->string($hoy)->string($hoy)      // i j k
                ->string($datos->correoProveedor)               // n
                ->string($datos->nombreProveedor)               // o
                ->string('19')                                  // p
                ->string('S')                                   // q
                ->integer((int) $empresa->numero)               // r  rut empresa
                ->integer((int) $datos->proveedor->numero)      // s  rut proveedor
                ->integer((int) $usuario->numero)               // t  rut usuario
                ->string($datos->linkConsulta);                 // u

            $w->string($hoy)->string($hoy)                      // p q
                ->string(null)                                  // r
                ->valor($documentos)                            // s  documentos (tal cual los lista el SII)
                ->string(null)                                  // t
                ->string($razonSocial)                          // u
                ->long(0)                                       // v
                ->nulo()                                        // w
                ->integer((int) $empresa->numero)               // x
                ->nulo()                                        // y
                ->string(null);                                 // z
        }]]);

        // Desde aca la orden puede haber llegado: toda falla es "incierta".
        try {
            $respuesta = $this->navegador->pedir($sesion, 'POST', self::BASE.'facade', $cuerpo, $this->cabeceras(), self::SEGUNDOS);
            $this->navegador->sesionVigente($respuesta->cuerpo);
            $resultado = GwtRpc::leer($respuesta->cuerpo, self::ESQUEMAS)->objeto();
        } catch (PostulacionException|SesionCaducaException $e) {
            throw new DeclaracionInciertaException((string) $empresa, $e->getMessage());
        }

        if ($resultado === null || $resultado['@tipo'] !== self::POSTULACION || ($resultado['campos'][0][1] ?? null) !== 'P91') {
            throw new DeclaracionInciertaException((string) $empresa, 'El SII respondio sin el estado P91.');
        }

        return new EstadoDeclaracion($empresa, 'P91', "DECLARACION EFECTUADA {$usuario}", $estado->paso);
    }

    /**
     * Solicita la revisión del set de pruebas de boletas (portal `?SET=2`, botón
     * "Solicitar validación") con el TrackID del envío de las boletas.
     *
     * El resultado NO es inmediato: el SII lo manda por correo y, si aprueba,
     * estado() pasa a P90 (declarar cumplimiento). Pedirla con un envío que tuvo
     * rechazos o reparos no sirve: revisarlo antes con SiiSoapClient::estadoEnvio().
     *
     * @throws SesionCaducaException
     * @throws PostulacionException
     */
    public function solicitarRevisionSet(SesionSii $sesion, string $rutEmpresa, string $trackId): void
    {
        $empresa = Rut::de($rutEmpresa, 'RUT de la empresa');

        if (! preg_match('/^\d{1,15}$/', $trackId)) {
            throw new PostulacionException("Identificador de envío inválido: {$trackId}.");
        }

        $rut = fn (GwtRpc $w) => $w->integer((int) $empresa->numero);
        $dv = fn (GwtRpc $w) => $w->string($empresa->dv);

        // El portal manda "90" si la empresa tiene actividades afectas a IVA y "91" si no.
        $actividades = $this->llamar($sesion, 'recuperarNroActividadesVigAfecIva', [
            [GwtRpc::INTEGER, $rut],
            [GwtRpc::STRING, $dv],
        ])->objeto();
        $codigo = (int) ($actividades['valor'] ?? 0) > 0 ? '90' : '91';

        // ingresarTrackId(Integer rut, String dv, String, String codigo, Integer, String, String trackId)
        $respuesta = $this->llamar($sesion, 'ingresarTrackId', [
            [GwtRpc::INTEGER, $rut],
            [GwtRpc::STRING, $dv],
            [GwtRpc::STRING, fn (GwtRpc $w) => $w->string(null)],
            [GwtRpc::STRING, fn (GwtRpc $w) => $w->string($codigo)],
            [GwtRpc::INTEGER, fn (GwtRpc $w) => $w->integer(null)],
            [GwtRpc::STRING, fn (GwtRpc $w) => $w->string(null)],
            [GwtRpc::STRING, fn (GwtRpc $w) => $w->string($trackId)],
        ])->string();

        match ($respuesta) {
            '1' => null,
            'errorEstado' => throw new SetSinDescargarException(
                "El SII no acepta revisar el envío {$trackId} de {$empresa}: primero hay que descargar el set de prueba de boletas.",
            ),
            default => throw new PostulacionException(
                "El SII no recibió la solicitud de revisión del envío {$trackId} (respuesta: ".var_export($respuesta, true).'). Intenta más tarde.',
            ),
        };
    }

    /**
     * Descarga el set de pruebas de boletas en el portal (`?SET=1`, "Generación de
     * nuevo set de pruebas"), lo que deja registrada la descarga en el SII.
     * Devuelve el texto del set.
     *
     * ** Candado: ** si la empresa ya está en P90 (lista para declarar) NO se
     * descarga: el portal advierte que un set nuevo obliga a empezar de nuevo la
     * certificación.
     *
     * Replica el portal: obtenerPostulacionSeg(rut, dv, "90", "P90") y luego
     * GET DownloadFileServlet con el RUT del representante (el usuario de la
     * sesión) y el correo del proveedor de software.
     *
     * @throws SesionCaducaException
     * @throws PostulacionException
     */
    public function descargarSetPruebas(SesionSii $sesion, string $rutEmpresa, string $correoProveedor): string
    {
        $empresa = Rut::de($rutEmpresa, 'RUT de la empresa');
        $usuario = $this->usuario($sesion);
        DatosPostulacion::correo($correoProveedor, 'correo del proveedor de software');

        $enP90 = $this->llamar($sesion, 'obtenerPostulacionSeg', [
            [GwtRpc::INTEGER, fn (GwtRpc $w) => $w->integer((int) $empresa->numero)],
            [GwtRpc::STRING, fn (GwtRpc $w) => $w->string($empresa->dv)],
            [GwtRpc::STRING, fn (GwtRpc $w) => $w->string((string) self::PASO)],
            [GwtRpc::STRING, fn (GwtRpc $w) => $w->string('P90')],
        ])->esNulo() === false;

        if ($enP90 || $this->estado($sesion, (string) $empresa)->porDeclarar()) {
            throw new PostulacionException(
                "No se descarga un set nuevo para {$empresa}: ya está lista para declarar cumplimiento y un set nuevo reiniciaría su certificación.",
            );
        }

        $url = self::BASE.'DownloadFileServlet?'.http_build_query([
            'rutEmpresa' => $empresa->numero,
            'dvEmpresa' => $empresa->dv,
            'rutRepre' => $usuario->numero,
            'dvRepre' => $usuario->dv,
            'mailProvSw' => $correoProveedor,
        ]);

        $respuesta = $this->navegador->pedir($sesion, 'GET', $url, null, ['Referer' => self::BASE.'?SET=1'], self::SEGUNDOS);
        $this->navegador->sesionVigente($respuesta->cuerpo);

        $texto = mb_check_encoding($respuesta->cuerpo, 'UTF-8') ? $respuesta->cuerpo : mb_convert_encoding($respuesta->cuerpo, 'UTF-8', 'ISO-8859-1');

        if ($respuesta->status !== 200 || ! str_contains($texto, 'SET DE PRUEBA') || ! str_contains($texto, 'CASO-')) {
            throw new PostulacionException("El SII no entregó el set de pruebas de {$empresa} (HTTP {$respuesta->status}). ".NavegadorSii::texto($texto, 200));
        }

        return $texto;
    }

    /**
     * Revisa, sin sesión ni login, que el portal de boletas siga siendo la versión
     * capturada: el archivo público de la app GWT (`<PERMUTACION>.cache.html`)
     * tiene que existir y traer la POLICY, los métodos y las firmas de tipo que se
     * usan. Si el SII cambia un DTO, cambia el hash de su firma y se detecta.
     *
     * @return list<string> problemas encontrados (vacío = vigente)
     *
     * @throws PostulacionException si no se pudo consultar (red / SII caído)
     */
    public function verificarPortal(): array
    {
        $respuesta = $this->navegador->pedir(new SesionSii, 'GET', self::BASE.self::PERMUTACION.'.cache.html', null, [], self::SEGUNDOS);

        if ($respuesta->status === 404) {
            return ['El SII publicó otra versión del portal de boletas: ya no existe la versión capturada ('.self::PERMUTACION.').'];
        }
        if ($respuesta->status !== 200) {
            throw new PostulacionException("No se pudo verificar el portal de boletas del SII (HTTP {$respuesta->status}).");
        }

        $esperado = [
            'POLICY '.self::POLICY => self::POLICY,
            'servicio' => self::SERVICIO,
            'tipo EstadoAutorizaTo' => self::ESTADO,
            'tipo PostulSegHistInsUpdTo' => self::POSTULACION,
            'tipo TdtEmpresaAutorizadaTo' => self::EMPRESA_AUTORIZADA,
            'tipo DocumentoAutorizadoTo' => self::DOCUMENTO,
            'DownloadFileServlet' => 'DownloadFileServlet',
        ];
        foreach (['obtenerEstadoAutorizaEmp', 'recuperarNombreContribuyente', 'listarDocAutorizados', 'autorizarEmpresaBolProd',
            'recuperarNroActividadesVigAfecIva', 'ingresarTrackId', 'obtenerPostulacionSeg'] as $metodo) {
            $esperado["método {$metodo}"] = "'{$metodo}'";
        }

        $problemas = [];
        foreach ($esperado as $nombre => $texto) {
            if (! str_contains($respuesta->cuerpo, $texto)) {
                $problemas[] = "El portal de boletas del SII cambió: falta {$nombre}.";
            }
        }

        return $problemas;
    }

    /** RUT del usuario autenticado (cookies RUT_NS/DV_NS de AUT2000). */
    private function usuario(SesionSii $sesion): Rut
    {
        $rut = $sesion->valor('RUT_NS');
        $dv = $sesion->valor('DV_NS');

        if ($rut === null || $dv === null) {
            throw new SesionCaducaException('La sesion del SII no trae el RUT del usuario; hay que volver a autenticar.');
        }

        return Rut::de("{$rut}-{$dv}", 'RUT del usuario autenticado');
    }

    /** @param list<array{0: string, 1: \Closure(GwtRpc): void}> $params */
    private function llamar(SesionSii $sesion, string $metodo, array $params): LectorGwt
    {
        $respuesta = $this->navegador->pedir($sesion, 'POST', self::BASE.'facade', $this->cuerpo($metodo, $params), $this->cabeceras(), self::SEGUNDOS);

        $this->navegador->sesionVigente($respuesta->cuerpo);

        if ($respuesta->status !== 200) {
            throw new PostulacionException("El portal de boletas del SII respondio HTTP {$respuesta->status}.");
        }

        return GwtRpc::leer($respuesta->cuerpo, self::ESQUEMAS);
    }

    /** @param list<array{0: string, 1: \Closure(GwtRpc): void}> $params */
    private function cuerpo(string $metodo, array $params): string
    {
        return GwtRpc::llamada(self::BASE, self::POLICY, self::SERVICIO, $metodo, $params);
    }

    /** @return array<string, string> */
    private function cabeceras(): array
    {
        return [
            'Content-Type' => 'text/x-gwt-rpc; charset=UTF-8',
            'X-GWT-Permutation' => self::PERMUTACION,
            'X-GWT-Module-Base' => self::BASE,
            'Origin' => 'https://'.self::HOST,
            'Referer' => self::BASE,
        ];
    }
}
