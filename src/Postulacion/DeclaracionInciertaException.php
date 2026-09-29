<?php

namespace Winex\Certificacion\Postulacion;

/**
 * La orden de grabar la declaracion de cumplimiento llego al SII, pero no se pudo
 * confirmar el resultado. La empresa puede haber quedado autorizada en produccion.
 *
 * No reintentar a ciegas: consultar DeclaracionBoletas::estado(). Si da P91 ya
 * esta autorizada; si sigue en P90 se puede volver a declarar.
 */
class DeclaracionInciertaException extends PostulacionException
{
    public function __construct(public readonly string $rutEmpresa, string $detalle = '')
    {
        parent::__construct(
            "La declaracion de cumplimiento de {$rutEmpresa} llego al SII, pero no se pudo confirmar si quedo grabada. "
            .'Consulta el estado antes de volver a declarar.'
            .($detalle !== '' ? " {$detalle}" : ''),
        );
    }
}
