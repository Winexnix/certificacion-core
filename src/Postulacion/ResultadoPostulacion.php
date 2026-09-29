<?php

namespace Winex\Certificacion\Postulacion;

final class ResultadoPostulacion
{
    /**
     * @param  list<string>  $avisos  paginas de aviso que el SII mostro y se pasaron con
     *                                "Continuar" (p. ej. que la empresa dejara de ser usuaria
     *                                del facturador gratuito del SII al autorizarse). Mostrarlas.
     * @param  string  $mensaje  texto de la pagina final del SII
     */
    public function __construct(
        public readonly Rut $empresa,
        public readonly array $avisos,
        public readonly string $mensaje,
    ) {}
}
