<?php

namespace Winex\Certificacion\Postulacion;

/**
 * Lee el payload (ya invertido) de una respuesta GWT-RPC.
 *
 * Un objeto se devuelve como `['@tipo' => firma, ...]`: `valor` para Integer/Long/
 * String, `elementos` para ArrayList y `campos` (lista de [clase, valor]) para los
 * DTO con esquema. Asi se puede volver a escribir tal cual con GwtRpc::valor().
 *
 * @internal
 */
final class LectorGwt
{
    private int $pos = 0;

    /** @var list<array<string, mixed>> objetos leidos, para las back-references */
    private array $objetos = [];

    /**
     * @param  list<mixed>  $tokens
     * @param  list<string>  $tabla
     * @param  array<string, string>  $esquemas
     */
    public function __construct(
        private readonly array $tokens,
        private readonly array $tabla,
        private readonly array $esquemas,
    ) {}

    /** El valor de retorno es null (sin leerlo: sirve para DTO sin esquema conocido). */
    public function esNulo(): bool
    {
        return ($this->tokens[$this->pos] ?? null) === 0;
    }

    public function string(): ?string
    {
        $i = $this->token();

        if (! is_int($i) || $i < 0 || $i > count($this->tabla)) {
            throw new PostulacionException('Respuesta GWT del SII mal formada (string).');
        }

        return $i === 0 ? null : $this->tabla[$i - 1];
    }

    /** @return array<string, mixed>|null */
    public function objeto(): ?array
    {
        $t = $this->token();

        if ($t === 0) {
            return null;
        }
        if (! is_int($t) || $t > count($this->tabla)) {
            throw new PostulacionException('Respuesta GWT del SII mal formada (objeto).');
        }
        if ($t < 0) {
            return $this->objetos[-$t - 1] ?? throw new PostulacionException('Respuesta GWT del SII mal formada (referencia).');
        }

        $firma = $this->tabla[$t - 1];
        $id = count($this->objetos);
        $this->objetos[] = ['@tipo' => $firma];

        $objeto = ['@tipo' => $firma];

        switch ($firma) {
            case GwtRpc::INTEGER:
                $objeto['valor'] = $this->int();
                break;

            case GwtRpc::LONG:
                $l = $this->token();
                $objeto['valor'] = is_array($l) ? GwtRpc::longDeBase64($l['@long']) : (int) $l;
                break;

            case GwtRpc::STRING:
                $objeto['valor'] = $this->string();
                break;

            case GwtRpc::ARRAY_LIST:
                $objeto['elementos'] = [];
                for ($n = $this->int(), $k = 0; $k < $n; $k++) {
                    $objeto['elementos'][] = $this->objeto();
                }
                break;

            default:
                if (! isset($this->esquemas[$firma])) {
                    throw new PostulacionException("El SII respondio un tipo no esperado ({$firma}); pudo haber cambiado el portal.");
                }
                $objeto['campos'] = [];
                foreach (str_split($this->esquemas[$firma]) as $clase) {
                    $objeto['campos'][] = [$clase, match ($clase) {
                        'S' => $this->string(),
                        'B' => $this->int(),
                        default => $this->objeto(),
                    }];
                }
        }

        return $this->objetos[$id] = $objeto;
    }

    private function int(): int
    {
        $v = $this->token();

        if (! is_int($v)) {
            throw new PostulacionException('Respuesta GWT del SII mal formada (entero).');
        }

        return $v;
    }

    private function token(): mixed
    {
        if ($this->pos >= count($this->tokens)) {
            throw new PostulacionException('Respuesta GWT del SII cortada.');
        }

        return $this->tokens[$this->pos++];
    }
}
