<?php

namespace Winex\Certificacion\Folios;

/**
 * Ambiente del SII donde se timbran los folios.
 *
 * Es un parametro obligatorio y no un string configurable a proposito: cada app
 * fija el suyo en codigo. Timbrar en el ambiente equivocado gasta folios reales o deja
 * al cliente con un CAF que no sirve.
 */
enum Ambiente: string
{
    case Certificacion = 'certificacion';
    case Produccion = 'produccion';

    /** Host del asistente de timbraje (cvc_cgi/dte). */
    public function host(): string
    {
        return match ($this) {
            self::Certificacion => 'maullin.sii.cl',
            self::Produccion => 'palena.sii.cl',
        };
    }

    public function portal(): string
    {
        return 'https://'.$this->host();
    }

    /**
     * IDK que trae el <DA> de los CAF de este ambiente. Sirve de candado: un CAF
     * con otro IDK salio del ambiente equivocado.
     */
    public function idk(): string
    {
        return match ($this) {
            self::Certificacion => '100',
            self::Produccion => '300',
        };
    }
}
