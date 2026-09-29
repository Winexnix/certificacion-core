<?php

namespace Winex\Certificacion\Folios;

/**
 * Transporte que ademas sabe enviar un cuerpo tal cual (p. ej. GWT-RPC de
 * www4.sii.cl). Aparte de TransporteHttp para no romper implementaciones que ya
 * existan en las apps.
 */
interface TransporteCuerpoCrudo extends TransporteHttp
{
    /**
     * @param  array<string, string>  $cabeceras  incluye el Content-Type del cuerpo
     *
     * @throws SolicitudFoliosException si falla la red
     */
    public function enviarCrudo(string $metodo, string $url, array $cabeceras, string $cuerpo, int $segundosRespuesta): RespuestaHttp;
}
