<?php

namespace Winex\Certificacion\Folios;

/**
 * Cookies de la sesion AUT2000 del contribuyente en los hosts *.sii.cl.
 *
 * ES UNA CREDENCIAL: con estas cookies se timbran folios sin el certificado. Si
 * el llamador la guarda (toArray/fromArray, para no re-autenticar en cada
 * solicitud: el SII frena el re-login seguido), tiene que guardarla cifrada.
 */
final class SesionSii
{
    /** @var array<string, array{name: string, value: string, domain: string, host_only: bool}> */
    private array $cookies = [];

    /** @param list<array{name: string, value: string, domain: string, host_only: bool}> $cookies */
    public static function fromArray(array $cookies): self
    {
        $sesion = new self;

        foreach ($cookies as $c) {
            if (isset($c['name'], $c['value'], $c['domain'])) {
                $sesion->poner((string) $c['name'], (string) $c['value'], (string) $c['domain'], (bool) ($c['host_only'] ?? false));
            }
        }

        return $sesion;
    }

    /** @return list<array{name: string, value: string, domain: string, host_only: bool}> */
    public function toArray(): array
    {
        return array_values($this->cookies);
    }

    public function tiene(string $nombre): bool
    {
        return $this->valor($nombre) !== null;
    }

    /** Valor de la primera cookie con ese nombre (null si no esta o esta vacia). */
    public function valor(string $nombre): ?string
    {
        foreach ($this->cookies as $c) {
            if ($c['name'] === $nombre && $c['value'] !== '') {
                return $c['value'];
            }
        }

        return null;
    }

    /** Valor de la cabecera Cookie para una URL (null si no corresponde ninguna). */
    public function cabeceraPara(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $pares = [];

        foreach ($this->cookies as $c) {
            $aplica = $c['host_only']
                ? $host === $c['domain']
                : ($host === $c['domain'] || str_ends_with($host, '.'.$c['domain']));

            if ($aplica) {
                $pares[] = "{$c['name']}={$c['value']}";
            }
        }

        return $pares === [] ? null : implode('; ', $pares);
    }

    /** Guarda las Set-Cookie de una respuesta de `$url`. */
    public function recibir(string $url, RespuestaHttp $respuesta): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach ($respuesta->cabecera('set-cookie') as $linea) {
            $this->aplicar($linea, $host);
        }
    }

    /**
     * El cuerpo de CAutInicio.cgi pone las NETSCAPE_LIVEWIRE.* por JavaScript
     * (`document.cookie = "..."`): se replican aca.
     */
    public function recibirDesdeHtml(string $html): void
    {
        if (preg_match_all('/document\.cookie\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $linea) {
                $this->aplicar($linea, 'sii.cl', dominioPorDefecto: true);
            }
        }
    }

    private function aplicar(string $linea, string $host, bool $dominioPorDefecto = false): void
    {
        $partes = array_map('trim', explode(';', $linea));
        $par = array_shift($partes);

        if ($par === null || ! str_contains($par, '=')) {
            return;
        }

        [$nombre, $valor] = array_map('trim', explode('=', $par, 2));
        if ($nombre === '') {
            return;
        }

        $dominio = null;
        $borrar = false;

        foreach ($partes as $atributo) {
            [$clave, $val] = array_pad(array_map('trim', explode('=', $atributo, 2)), 2, '');
            $clave = strtolower($clave);

            if ($clave === 'domain' && $val !== '') {
                $dominio = strtolower(ltrim($val, '.'));
            } elseif ($clave === 'max-age' && is_numeric($val) && (int) $val <= 0) {
                $borrar = true;
            } elseif ($clave === 'expires' && ($ts = strtotime($val)) !== false && $ts < time()) {
                $borrar = true;
            }
        }

        // Una cookie solo puede fijarse para su propio host o un dominio padre, y
        // nunca fuera de sii.cl.
        if ($dominio !== null && $host !== $dominio && ! str_ends_with($host, '.'.$dominio)) {
            return;
        }
        if ($dominio !== null && $dominio !== 'sii.cl' && ! str_ends_with($dominio, '.sii.cl')) {
            return;
        }

        $hostOnly = $dominio === null && ! $dominioPorDefecto;
        $dominio ??= $host;

        if ($borrar || $valor === '') {
            unset($this->cookies[$this->clave($nombre, $dominio, $hostOnly)]);

            return;
        }

        $this->poner($nombre, $valor, $dominio, $hostOnly);
    }

    private function poner(string $nombre, string $valor, string $dominio, bool $hostOnly): void
    {
        $this->cookies[$this->clave($nombre, $dominio, $hostOnly)] = [
            'name' => $nombre,
            'value' => $valor,
            'domain' => $dominio,
            'host_only' => $hostOnly,
        ];
    }

    private function clave(string $nombre, string $dominio, bool $hostOnly): string
    {
        return ($hostOnly ? 'h:' : 'd:')."{$dominio}|{$nombre}";
    }
}
