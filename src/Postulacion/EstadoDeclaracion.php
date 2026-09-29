<?php

namespace Winex\Certificacion\Postulacion;

/**
 * Estado de la certificacion de boletas de una empresa en www4 (tabla de
 * seguimiento del SII). Codigos vistos: P90 = falta declarar cumplimiento,
 * P91 = declaracion efectuada (autorizada en produccion).
 */
final class EstadoDeclaracion
{
    public function __construct(
        public readonly Rut $empresa,
        /** null si el SII no tiene la empresa en el proceso de boletas */
        public readonly ?string $codigo,
        public readonly string $glosa,
        /** codigo numerico del paso (90), el que se reenvia al declarar */
        public readonly ?int $paso,
    ) {}

    public function porDeclarar(): bool
    {
        return $this->codigo === 'P90';
    }

    public function autorizada(): bool
    {
        return $this->codigo === 'P91';
    }
}
