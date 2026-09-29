<?php

namespace Winex\Certificacion\Certificacion;

/**
 * Estado de un envío subido al SII (QueryEstUp.getEstUp), por TrackID.
 *
 * Capturado 2026-09-15 en maullin para sets de boletas:
 *   <SII:RESP_BODY><TIPO_DOCTO>39</TIPO_DOCTO><INFORMADOS>5</INFORMADOS><ACEPTADOS>5</ACEPTADOS>
 *   <RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS></SII:RESP_BODY>
 *   <SII:RESP_HDR><TRACKID>…</TRACKID><ESTADO>EPR</ESTADO><GLOSA>Envio Procesado</GLOSA>…
 */
final class EstadoEnvio
{
    /** Estados intermedios: el SII todavía no termina de validar el envío. */
    private const EN_PROCESO = ['REC', 'SOK', 'CRT', 'FOK', 'PRD'];

    public function __construct(
        public readonly string $trackId,
        public readonly string $estado,
        public readonly string $glosa,
        public readonly int $informados = 0,
        public readonly int $aceptados = 0,
        public readonly int $rechazados = 0,
        public readonly int $reparos = 0,
    ) {}

    /**
     * @throws \RuntimeException si la respuesta no trae ESTADO
     */
    public static function desdeRespuesta(string $respuesta, string $trackId): self
    {
        $xml = html_entity_decode($respuesta, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $valor = static function (string $tag) use ($xml): ?string {
            return preg_match("/<{$tag}>\\s*([^<]*?)\\s*<\\/{$tag}>/", $xml, $m) ? $m[1] : null;
        };

        $estado = $valor('ESTADO');
        if ($estado === null || $estado === '') {
            throw new \RuntimeException('El SII no devolvió el estado del envío '.$trackId.'.');
        }

        // Varios tipos de documento en un envío: se suman.
        $sumar = static function (string $tag) use ($xml): int {
            return preg_match_all("/<{$tag}>\\s*(\\d+)\\s*<\\/{$tag}>/", $xml, $m) ? array_sum(array_map('intval', $m[1])) : 0;
        };

        return new self(
            $valor('TRACKID') ?: $trackId,
            $estado,
            (string) $valor('GLOSA'),
            $sumar('INFORMADOS'),
            $sumar('ACEPTADOS'),
            $sumar('RECHAZADOS'),
            $sumar('REPAROS'),
        );
    }

    /** El SII todavía está validando: hay que volver a consultar más tarde. */
    public function enProceso(): bool
    {
        return in_array($this->estado, self::EN_PROCESO, true);
    }

    public function procesado(): bool
    {
        return $this->estado === 'EPR';
    }

    /** Procesado con todos los documentos aceptados, sin rechazos ni reparos. */
    public function limpio(): bool
    {
        return $this->procesado()
            && $this->informados > 0
            && $this->aceptados === $this->informados
            && $this->rechazados === 0
            && $this->reparos === 0;
    }

    public function resumen(): string
    {
        return $this->procesado()
            ? "{$this->estado} {$this->glosa}: {$this->informados} informados, {$this->aceptados} aceptados, {$this->rechazados} rechazados, {$this->reparos} con reparos"
            : trim("{$this->estado} {$this->glosa}");
    }
}
