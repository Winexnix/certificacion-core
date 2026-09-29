<?php

namespace Winex\Certificacion\Postulacion;

/**
 * RUT chileno validado (digito verificador incluido). Acepta "76123456-0",
 * "76.123.456-0" o "761234560".
 */
final class Rut
{
    private function __construct(
        public readonly string $numero,
        public readonly string $dv,
    ) {}

    /** @throws PostulacionException */
    public static function de(string $rut, string $campo = 'RUT'): self
    {
        $limpio = strtoupper((string) preg_replace('/[^0-9kK]/', '', $rut));

        if (strlen($limpio) < 2 || strlen($limpio) > 9 || ! ctype_digit(substr($limpio, 0, -1))) {
            throw new PostulacionException("{$campo} invalido: {$rut}.");
        }

        $numero = ltrim(substr($limpio, 0, -1), '0');
        $dv = substr($limpio, -1);

        if ($numero === '' || self::calcularDv($numero) !== $dv) {
            throw new PostulacionException("{$campo} invalido (digito verificador): {$rut}.");
        }

        return new self($numero, $dv);
    }

    public function __toString(): string
    {
        return "{$this->numero}-{$this->dv}";
    }

    private static function calcularDv(string $numero): string
    {
        $suma = 0;
        $factor = 2;

        for ($i = strlen($numero) - 1; $i >= 0; $i--) {
            $suma += (int) $numero[$i] * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }

        $resto = 11 - ($suma % 11);

        return match ($resto) {
            11 => '0',
            10 => 'K',
            default => (string) $resto,
        };
    }
}
