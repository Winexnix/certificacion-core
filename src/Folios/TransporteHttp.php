<?php

namespace Winex\Certificacion\Folios;

/**
 * Una sola peticion HTTP, sin seguir redirecciones ni manejar cookies: eso lo
 * hace SolicitudFolios, para poder revisar cada salto contra el ambiente.
 *
 * Existe para que las apps puedan probar el flujo sin red (ver TransporteFalso).
 */
interface TransporteHttp
{
    /**
     * @param  array<string, string>  $cabeceras
     * @param  array<string, string>|null  $formulario  cuerpo x-www-form-urlencoded (null = sin cuerpo)
     * @param  array{cert: string, key: string}|null  $tls  certificado cliente en PEM (cadena y llave)
     *
     * @throws SolicitudFoliosException si falla la red
     */
    public function enviar(string $metodo, string $url, array $cabeceras, ?array $formulario, ?array $tls, int $segundosRespuesta): RespuestaHttp;
}
