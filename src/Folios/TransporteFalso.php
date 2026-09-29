<?php

namespace Winex\Certificacion\Folios;

/**
 * Transporte para tests: responde segun la URL y registra lo que se envio. Nunca
 * sale a la red.
 *
 *     $falso = new TransporteFalso([
 *         'herculesr.sii.cl/cgi_AUT2000/*' => '<script>document.cookie="TOKEN=X"</script>',
 *         'maullin.sii.cl/cvc_cgi/dte/of_solicita_folios' => $html,
 *     ]);
 *     $solicitud = new SolicitudFolios(Ambiente::Certificacion, $falso);
 *
 * Los patrones se comparan contra host+ruta (sin esquema ni query); `*` es comodin.
 * Gana el primer patron que calce. Una URL sin patron responde 404.
 */
final class TransporteFalso implements TransporteCuerpoCrudo
{
    /** @var list<array{metodo: string, url: string, cabeceras: array<string, string>, formulario: array<string, string>|null, tls: bool, cuerpo?: string}> */
    public array $enviadas = [];

    /**
     * Un Closure recibe (url, formulario) y devuelve la respuesta, o lanza para
     * simular una falla de red.
     *
     * @param  array<string, string|RespuestaHttp|\Closure>  $respuestas
     */
    public function __construct(private array $respuestas) {}

    public function enviar(string $metodo, string $url, array $cabeceras, ?array $formulario, ?array $tls, int $segundosRespuesta): RespuestaHttp
    {
        $this->enviadas[] = [
            'metodo' => $metodo,
            'url' => $url,
            'cabeceras' => $cabeceras,
            'formulario' => $formulario,
            'tls' => $tls !== null,
        ];

        return $this->responder($url, $formulario);
    }

    /** Un Closure recibe (url, cuerpo) en vez de (url, formulario). */
    public function enviarCrudo(string $metodo, string $url, array $cabeceras, string $cuerpo, int $segundosRespuesta): RespuestaHttp
    {
        $this->enviadas[] = [
            'metodo' => $metodo,
            'url' => $url,
            'cabeceras' => $cabeceras,
            'formulario' => null,
            'tls' => false,
            'cuerpo' => $cuerpo,
        ];

        return $this->responder($url, $cuerpo);
    }

    /** @param array<string, string>|string|null $enviado */
    private function responder(string $url, array|string|null $enviado): RespuestaHttp
    {
        $partes = parse_url($url);
        $clave = ($partes['host'] ?? '').($partes['path'] ?? '');

        foreach ($this->respuestas as $patron => $respuesta) {
            $regex = '#^'.str_replace('\*', '.*', preg_quote($patron, '#')).'$#i';

            if (preg_match($regex, $clave)) {
                if ($respuesta instanceof \Closure) {
                    $respuesta = $respuesta($url, $enviado);
                }

                return $respuesta instanceof RespuestaHttp ? $respuesta : new RespuestaHttp(200, [], $respuesta);
            }
        }

        return new RespuestaHttp(404, [], '');
    }

    /** @return list<string> URLs enviadas, en orden */
    public function urls(): array
    {
        return array_column($this->enviadas, 'url');
    }
}
