<?php

namespace Winex\Certificacion\Postulacion;

/**
 * Lo minimo de GWT-RPC (protocolo 7) para hablar con `certBolElectDteInternet`.
 *
 * Peticion: `7|0|<n>|<tabla de strings>|<base>|<policy>|<servicio>|<metodo>|<nParams>|<tipos>|<valores>|`.
 * Los strings van por indice (1-based, 0 = null); un objeto va como indice de su
 * firma de tipo seguido de sus campos. Aca nunca se escriben back-references: el
 * servidor acepta objetos repetidos como instancias nuevas.
 *
 * Respuesta: `//OK[<payload>,[<tabla de strings>],0,7]` (o `//EX` si el servidor
 * lanzo). El payload se lee de atras hacia adelante; los negativos son
 * back-references a objetos ya leidos.
 *
 * @internal
 */
final class GwtRpc
{
    public const INTEGER = 'java.lang.Integer/3438268394';

    public const LONG = 'java.lang.Long/4227064769';

    public const STRING = 'java.lang.String/2004016611';

    public const ARRAY_LIST = 'java.util.ArrayList/4159755760';

    // --- escritura ----------------------------------------------------------------

    /** @var array<string, int> */
    private array $tabla = [];

    /** @var list<string> */
    private array $tokens = [];

    /** Objetos escritos (sus ids son 1..n, para las back-references). */
    private int $objetos = 0;

    /** @var array<int, int> valor de Long cacheado => id del objeto */
    private array $longsCacheados = [];

    private function __construct() {}

    /**
     * Arma una llamada. `$params` son pares [firmaDeTipo, closure que escribe el valor].
     *
     * @param  list<array{0: string, 1: \Closure(self): void}>  $params
     */
    public static function llamada(string $base, string $policy, string $servicio, string $metodo, array $params): string
    {
        $w = new self;

        foreach ([$base, $policy, $servicio, $metodo] as $s) {
            $w->tokens[] = (string) $w->indice($s);
        }

        $w->tokens[] = (string) count($params);
        foreach ($params as [$tipo]) {
            $w->tokens[] = (string) $w->indice($tipo);
        }
        foreach ($params as [, $escribir]) {
            $escribir($w);
        }

        $strings = array_map(self::escapar(...), array_keys($w->tabla));

        return '7|0|'.count($strings).'|'.implode('|', $strings).'|'.implode('|', $w->tokens).'|';
    }

    /** Campo/parametro declarado String (va directo, sin firma de tipo). */
    public function string(?string $valor): self
    {
        $this->tokens[] = $valor === null ? '0' : (string) $this->indice($valor);

        return $this;
    }

    public function int(int $valor): self
    {
        $this->tokens[] = (string) $valor;

        return $this;
    }

    public function nulo(): self
    {
        $this->tokens[] = '0';

        return $this;
    }

    /** Campo declarado como objeto que lleva un java.lang.Integer. */
    public function integer(?int $valor): self
    {
        return $valor === null ? $this->nulo() : $this->tipo(self::INTEGER)->int($valor);
    }

    /**
     * Campo declarado como objeto que lleva un java.lang.Long (base64 de GWT).
     * Como Long.valueOf() en el cliente, -128..127 es la misma instancia: la
     * segunda vez va como back-reference (asi lo manda el navegador).
     */
    public function long(?int $valor): self
    {
        if ($valor === null) {
            return $this->nulo();
        }

        if (isset($this->longsCacheados[$valor])) {
            $this->tokens[] = (string) -$this->longsCacheados[$valor];

            return $this;
        }

        $this->tipo(self::LONG);
        $this->tokens[] = self::longBase64($valor);

        if ($valor >= -128 && $valor <= 127) {
            $this->longsCacheados[$valor] = $this->objetos;
        }

        return $this;
    }

    /** Inicio de un objeto: su firma de tipo. Los campos se escriben despues, en orden. */
    public function tipo(string $firma): self
    {
        $this->objetos++;
        $this->tokens[] = (string) $this->indice($firma);

        return $this;
    }

    /**
     * Escribe un valor leido de una respuesta (ver leer()) tal cual vino.
     *
     * @param  array<string, mixed>|int|string|null  $valor
     */
    public function valor(mixed $valor): self
    {
        if ($valor === null) {
            return $this->nulo();
        }
        if (is_array($valor) && ($valor['@tipo'] ?? null) === self::LONG) {
            return $this->long($valor['valor']);
        }
        if (is_array($valor) && isset($valor['@tipo'])) {
            $this->tipo($valor['@tipo']);

            match ($valor['@tipo']) {
                self::INTEGER => $this->int($valor['valor']),
                self::STRING => $this->string($valor['valor']),
                self::ARRAY_LIST => $this->lista($valor['elementos']),
                default => $this->campos($valor['campos']),
            };

            return $this;
        }

        throw new PostulacionException('GWT: valor que no se sabe escribir.');
    }

    /** @param list<mixed> $elementos */
    private function lista(array $elementos): void
    {
        $this->int(count($elementos));
        foreach ($elementos as $e) {
            $this->valor($e);
        }
    }

    /** @param list<array{0: string, 1: mixed}> $campos */
    private function campos(array $campos): void
    {
        foreach ($campos as [$clase, $v]) {
            match ($clase) {
                'S' => $this->string($v),
                'B' => $this->int((int) $v),
                default => $this->valor($v),
            };
        }
    }

    private function indice(string $s): int
    {
        return $this->tabla[$s] ??= count($this->tabla) + 1;
    }

    private static function escapar(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '|' => '\\!', "\0" => '\\0']);
    }

    private static function longBase64(int $valor): string
    {
        $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789$_';

        if ($valor === 0) {
            return 'A';
        }

        $s = '';
        $v = $valor;
        while ($v !== 0 && $v !== -1) {
            $s = $alfabeto[$v & 63].$s;
            $v >>= 6;
        }

        return $s;
    }

    // --- lectura ------------------------------------------------------------------

    /**
     * Esquemas de objetos conocidos: por firma de tipo, la clase de cada campo en
     * orden de serializacion ('S' = declarado String, 'O' = objeto).
     *
     * El valor de retorno se lee con string() si el metodo declara String, o con
     * objeto() en cualquier otro caso.
     *
     * @param  array<string, string>  $esquemas
     */
    public static function leer(string $respuesta, array $esquemas = []): LectorGwt
    {
        $respuesta = trim($respuesta);

        if (str_starts_with($respuesta, '//EX')) {
            throw new PostulacionException('El servicio del SII respondio con un error. '.self::detalleExcepcion($respuesta));
        }
        if (! str_starts_with($respuesta, '//OK')) {
            throw new PostulacionException('Respuesta inesperada del servicio del SII: '.mb_substr($respuesta, 0, 200));
        }

        $datos = self::parsear(substr($respuesta, 4));
        if (! is_array($datos) || count($datos) < 3) {
            throw new PostulacionException('Respuesta GWT del SII mal formada.');
        }

        array_pop($datos); // version
        array_pop($datos); // flags
        $tabla = array_pop($datos);

        if (! is_array($tabla)) {
            throw new PostulacionException('Respuesta GWT del SII mal formada.');
        }

        return new LectorGwt(array_reverse($datos), $tabla, $esquemas);
    }

    private static function detalleExcepcion(string $respuesta): string
    {
        if (str_contains($respuesta, 'IncompatibleRemoteServiceException')) {
            return '(el SII actualizo el portal de boletas: hay que volver a capturar la version de GWT)';
        }

        if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $respuesta, $m)) {
            foreach ($m[1] as $s) {
                if (! str_contains($s, '/') && ! str_starts_with($s, 'java.')) {
                    return '(SII: '.mb_substr(stripcslashes($s), 0, 300).')';
                }
            }
        }

        return '';
    }

    /** JSON de GWT: como JSON pero los long van entre comillas simples y los strings pueden traer \x. */
    private static function parsear(string $texto): mixed
    {
        $i = 0;
        $n = strlen($texto);

        $valor = function () use (&$valor, $texto, $n, &$i): mixed {
            while ($i < $n && ctype_space($texto[$i])) {
                $i++;
            }

            $c = $texto[$i] ?? '';

            if ($c === '[') {
                $i++;
                $lista = [];
                while (true) {
                    while ($i < $n && ctype_space($texto[$i])) {
                        $i++;
                    }
                    if (($texto[$i] ?? '') === ']') {
                        $i++;

                        return $lista;
                    }
                    $lista[] = $valor();
                    while ($i < $n && ctype_space($texto[$i])) {
                        $i++;
                    }
                    if (($texto[$i] ?? '') === ',') {
                        $i++;
                    }
                    if ($i >= $n) {
                        throw new PostulacionException('Respuesta GWT del SII cortada.');
                    }
                }
            }

            if ($c === '"' || $c === "'") {
                $cierre = $c;
                $i++;
                $s = '';
                while ($i < $n && $texto[$i] !== $cierre) {
                    if ($texto[$i] === '\\' && $i + 1 < $n) {
                        $e = $texto[++$i];
                        if ($e === 'u') {
                            $s .= mb_chr((int) hexdec(substr($texto, $i + 1, 4)), 'UTF-8');
                            $i += 4;
                        } elseif ($e === 'x') {
                            $s .= mb_chr((int) hexdec(substr($texto, $i + 1, 2)), 'UTF-8');
                            $i += 2;
                        } else {
                            $s .= match ($e) { 'n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0", default => $e };
                        }
                    } else {
                        $s .= $texto[$i];
                    }
                    $i++;
                }
                $i++;

                return $cierre === "'" ? ['@long' => $s] : $s;
            }

            if (preg_match('/-?\d+(\.\d+)?/A', $texto, $m, 0, $i)) {
                $i += strlen($m[0]);

                return (int) $m[0];
            }

            throw new PostulacionException('Respuesta GWT del SII mal formada.');
        };

        return $valor();
    }

    /** Long en base64 de GWT a int. */
    public static function longDeBase64(string $s): int
    {
        $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789$_';
        $v = 0;

        foreach (str_split($s) as $ch) {
            $v = ($v << 6) | strpos($alfabeto, $ch);
        }

        return $v;
    }
}
