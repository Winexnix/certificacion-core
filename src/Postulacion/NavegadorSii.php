<?php

namespace Winex\Certificacion\Postulacion;

use Winex\Certificacion\Folios\RespuestaHttp;
use Winex\Certificacion\Folios\SesionCaducaException;
use Winex\Certificacion\Folios\SesionSii;
use Winex\Certificacion\Folios\SolicitudFoliosException;
use Winex\Certificacion\Folios\TransporteCuerpoCrudo;
use Winex\Certificacion\Folios\TransporteHttp;

/**
 * HTTP con la sesion AUT2000 contra UN host del SII: sigue redirecciones a mano y
 * corta si alguna sale de ese host (o cae en el login).
 *
 * @internal lo usan PostulacionSii y DeclaracionBoletas
 */
final class NavegadorSii
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';

    private const MAX_REDIRECCIONES = 10;

    /** Textos que delatan que el SII devolvio (o redirigio a) el login. */
    private const MARCADORES_LOGIN = ['autInicioDTE', 'IngresoCertificado', 'InicioAutenticacion', 'CAutInicio.cgi', 'CAutValida'];

    public function __construct(
        private readonly TransporteHttp $transporte,
        private readonly string $host,
    ) {}

    /**
     * Envia a `$url` (tiene que ser del host) y devuelve la respuesta final.
     *
     * `$cuerpo` es un formulario (array, x-www-form-urlencoded) o un cuerpo crudo
     * (string) con su Content-Type en `$cabeceras`.
     *
     * @param  array<string, string>|string|null  $cuerpo
     * @param  array<string, string>  $cabeceras
     *
     * @throws SesionCaducaException
     * @throws PostulacionException
     */
    public function pedir(SesionSii $sesion, string $metodo, string $url, array|string|null $cuerpo, array $cabeceras, int $segundos): RespuestaHttp
    {
        if (is_string($cuerpo) && ! $this->transporte instanceof TransporteCuerpoCrudo) {
            throw new PostulacionException('El transporte HTTP no sabe enviar cuerpos crudos (implementa TransporteCuerpoCrudo).');
        }

        for ($salto = 0; $salto <= self::MAX_REDIRECCIONES; $salto++) {
            $this->soloHost($url);

            $enviar = $cabeceras + ['User-Agent' => self::UA];
            if (($cookie = $sesion->cabeceraPara($url)) !== null) {
                $enviar['Cookie'] = $cookie;
            }

            try {
                $respuesta = is_string($cuerpo)
                    ? $this->transporte->enviarCrudo($metodo, $url, $enviar, $cuerpo, $segundos)
                    : $this->transporte->enviar($metodo, $url, $enviar, $cuerpo, null, $segundos);
            } catch (SolicitudFoliosException $e) {
                throw new PostulacionException($e->getMessage(), 0, $e);
            }

            $sesion->recibir($url, $respuesta);

            if (! $respuesta->esRedireccion()) {
                return $respuesta;
            }

            $anterior = $url;
            $url = self::resolverUrl($anterior, $respuesta->cabecera('location')[0]);
            $cabeceras['Referer'] = $anterior;

            // Como los navegadores: 301/302/303 tras un POST siguen con GET sin cuerpo.
            if ($respuesta->status !== 307 && $respuesta->status !== 308) {
                $metodo = 'GET';
                $cuerpo = null;
            }
        }

        throw new PostulacionException('El SII redirigio demasiadas veces.');
    }

    /**
     * Pagina HTML (ISO-8859-1 del SII, se devuelve en UTF-8).
     *
     * @param  array<string, string>|null  $formulario
     */
    public function pagina(SesionSii $sesion, string $metodo, string $url, ?array $formulario, string $referer, int $segundos): string
    {
        $cuerpo = $this->pedir($sesion, $metodo, $url, $formulario, ['Referer' => $referer], $segundos)->cuerpo;

        if (! mb_check_encoding($cuerpo, 'UTF-8')) {
            $cuerpo = mb_convert_encoding($cuerpo, 'UTF-8', 'ISO-8859-1');
        }

        $this->sesionVigente($cuerpo);

        return $cuerpo;
    }

    public function sesionVigente(string $texto): void
    {
        foreach (self::MARCADORES_LOGIN as $marcador) {
            if (str_contains($texto, $marcador)) {
                throw new SesionCaducaException('La sesion del SII caduco; hay que volver a autenticar.');
            }
        }
    }

    private function soloHost(string $url): void
    {
        $this->sesionVigente($url);

        $partes = parse_url($url);

        if (($partes['scheme'] ?? '') !== 'https' || strtolower($partes['host'] ?? '') !== $this->host) {
            throw new PostulacionException("Operacion detenida: el SII apunto fuera de {$this->host}.");
        }
    }

    // --- parseo HTML --------------------------------------------------------------

    /**
     * Primer <form> de la pagina: action y lo que un navegador enviaria (checkbox y
     * radio solo si vienen marcados; ni botones ni campos disabled).
     *
     * @return array{action: string, inputs: array<string, string>}|null
     */
    public static function form(string $html): ?array
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $form = $doc->getElementsByTagName('form')->item(0);
        if (! $form instanceof \DOMElement) {
            return null;
        }

        $inputs = [];

        foreach ($form->getElementsByTagName('input') as $el) {
            $name = $el->getAttribute('name');
            $tipo = strtolower($el->getAttribute('type'));

            if ($name === '' || $el->hasAttribute('disabled') || in_array($tipo, ['button', 'reset', 'submit', 'image'], true)) {
                continue;
            }
            if (in_array($tipo, ['checkbox', 'radio'], true) && ! $el->hasAttribute('checked')) {
                continue;
            }

            $inputs[$name] = trim($el->getAttribute('value'));
        }

        return ['action' => $form->getAttribute('action'), 'inputs' => $inputs];
    }

    /** Texto visible de la pagina, recortado (para mensajes). */
    public static function texto(string $html, int $max = 300): string
    {
        $html = (string) preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));

        return mb_substr($texto, 0, $max);
    }

    /** Resuelve `$ref` (absoluta, //host, /ruta o relativa) contra `$desde`. */
    public static function resolverUrl(string $desde, string $ref): string
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

        return $raiz.substr($ruta, 0, (int) strrpos($ruta, '/') + 1).$ref;
    }
}
