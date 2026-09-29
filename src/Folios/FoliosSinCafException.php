<?php

namespace Winex\Certificacion\Folios;

/**
 * La orden de timbrar llego al SII (paso 4) y los folios pueden estar timbrados,
 * pero no se obtuvo el CAF. No hay que reintentar la solicitud (quemaria otro
 * rango): primero revisar "Folios ya autorizados" en el portal y bajar el CAF de ahi.
 */
class FoliosSinCafException extends SolicitudFoliosException
{
    public function __construct(
        public readonly int $folioDesde,
        public readonly int $folioHasta,
        public readonly Ambiente $ambiente,
        string $detalle = '',
    ) {
        parent::__construct(
            "La solicitud de los folios {$folioDesde} al {$folioHasta} llego a {$ambiente->host()} y pudo haberlos "
            .'timbrado, pero no se obtuvo el CAF. Revisa "Folios ya autorizados" en el portal del SII y bajalo de ahi; '
            .'no los vuelvas a pedir sin revisar.'
            .($detalle !== '' ? " {$detalle}" : ''),
        );
    }
}
