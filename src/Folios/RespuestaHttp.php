<?php

namespace Winex\Certificacion\Folios;

final class RespuestaHttp
{
    /**
     * @param  array<string, list<string>>  $cabeceras  nombres en minusculas
     */
    public function __construct(
        public readonly int $status,
        public readonly array $cabeceras,
        public readonly string $cuerpo,
    ) {}

    /** @return list<string> */
    public function cabecera(string $nombre): array
    {
        return $this->cabeceras[strtolower($nombre)] ?? [];
    }

    public function esRedireccion(): bool
    {
        return in_array($this->status, [301, 302, 303, 307, 308], true) && $this->cabecera('location') !== [];
    }
}
