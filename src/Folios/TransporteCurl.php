<?php

namespace Winex\Certificacion\Folios;

/**
 * Transporte real: cURL con verificacion TLS encendida y sin seguir redirecciones.
 */
final class TransporteCurl implements TransporteCuerpoCrudo
{
    private const SEGUNDOS_CONEXION = 15;

    public function enviar(string $metodo, string $url, array $cabeceras, ?array $formulario, ?array $tls, int $segundosRespuesta): RespuestaHttp
    {
        if ($formulario !== null) {
            $cabeceras['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        return $this->ejecutar($metodo, $url, $cabeceras, $formulario === null ? null : http_build_query($formulario), $tls, $segundosRespuesta);
    }

    public function enviarCrudo(string $metodo, string $url, array $cabeceras, string $cuerpo, int $segundosRespuesta): RespuestaHttp
    {
        return $this->ejecutar($metodo, $url, $cabeceras, $cuerpo, null, $segundosRespuesta);
    }

    /**
     * @param  array<string, string>  $cabeceras
     * @param  array{cert: string, key: string}|null  $tls
     */
    private function ejecutar(string $metodo, string $url, array $cabeceras, ?string $cuerpo, ?array $tls, int $segundosRespuesta): RespuestaHttp
    {
        $archivos = [];
        $recibidas = [];

        $ch = curl_init($url);

        $lineas = [];
        foreach ($cabeceras as $nombre => $valor) {
            $lineas[] = "{$nombre}: {$valor}";
        }

        $opciones = [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => self::SEGUNDOS_CONEXION,
            CURLOPT_TIMEOUT => $segundosRespuesta,
            // Acepta gzip/deflate y los descomprime.
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($ch, string $linea) use (&$recibidas): int {
                // Una redireccion 100-continue o similar reinicia el bloque de cabeceras.
                if (str_starts_with($linea, 'HTTP/')) {
                    $recibidas = [];
                } elseif (str_contains($linea, ':')) {
                    [$nombre, $valor] = explode(':', $linea, 2);
                    $recibidas[strtolower(trim($nombre))][] = trim($valor);
                }

                return strlen($linea);
            },
        ];

        if ($cuerpo !== null) {
            $opciones[CURLOPT_POSTFIELDS] = $cuerpo;
        }

        try {
            if ($tls !== null) {
                // Cadena completa (hoja + intermedios) y llave, en temporales 0600 que se
                // borran apenas termina la peticion.
                $archivos[] = $certFile = $this->temporal($tls['cert']);
                $archivos[] = $keyFile = $this->temporal($tls['key']);
                $opciones[CURLOPT_SSLCERT] = $certFile;
                $opciones[CURLOPT_SSLKEY] = $keyFile;
            }

            $opciones[CURLOPT_HTTPHEADER] = $lineas;
            curl_setopt_array($ch, $opciones);

            $cuerpo = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ($cuerpo === false) {
                $host = parse_url($url, PHP_URL_HOST);

                // El detalle de cURL va aparte (previous): puede nombrar los
                // temporales del certificado. El mensaje se puede mostrar.
                throw new SolicitudFoliosException(
                    "No se pudo conectar con el SII ({$host}).",
                    0,
                    new \RuntimeException('cURL '.curl_errno($ch).': '.curl_error($ch)),
                );
            }
        } finally {
            foreach ($archivos as $archivo) {
                @unlink($archivo);
            }
        }

        return new RespuestaHttp($status, $recibidas, (string) $cuerpo);
    }

    private function temporal(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'sii_');

        if ($ruta === false) {
            throw new SolicitudFoliosException('No se pudo crear un archivo temporal para el certificado.');
        }

        // Permisos antes del contenido: la llave no queda legible ni un instante.
        @chmod($ruta, 0600);
        file_put_contents($ruta, $contenido);

        return $ruta;
    }
}
