<?php

namespace Winex\Certificacion\Tests\Certificacion;

use PHPUnit\Framework\TestCase;
use Winex\Certificacion\Certificacion\EstadoEnvio;

final class EstadoEnvioTest extends TestCase
{
    /** @param list<array{string, int, int, int, int}> $tipos [tipo, informados, aceptados, rechazados, reparos] */
    private function respuesta(string $estado, string $glosa, array $tipos = []): string
    {
        $cuerpo = '';
        foreach ($tipos as [$tipo, $inf, $ace, $rec, $rep]) {
            $cuerpo .= "<TIPO_DOCTO>{$tipo}</TIPO_DOCTO><INFORMADOS>{$inf}</INFORMADOS><ACEPTADOS>{$ace}</ACEPTADOS>"
                ."<RECHAZADOS>{$rec}</RECHAZADOS><REPAROS>{$rep}</REPAROS>";
        }

        return "<SII:RESPUESTA xmlns:SII=\"http://www.sii.cl/XMLSchema\"><SII:RESP_BODY>{$cuerpo}</SII:RESP_BODY>"
            ."<SII:RESP_HDR><TRACKID>123</TRACKID><ESTADO>{$estado}</ESTADO><GLOSA>{$glosa}</GLOSA></SII:RESP_HDR></SII:RESPUESTA>";
    }

    public function test_envio_procesado_y_limpio(): void
    {
        $estado = EstadoEnvio::desdeRespuesta($this->respuesta('EPR', 'Envio Procesado', [['39', 5, 5, 0, 0]]), '999');

        $this->assertSame('123', $estado->trackId);
        $this->assertTrue($estado->procesado());
        $this->assertTrue($estado->limpio());
        $this->assertFalse($estado->enProceso());
        $this->assertStringContainsString('5 informados, 5 aceptados', $estado->resumen());
    }

    public function test_con_rechazos_no_es_limpio(): void
    {
        $estado = EstadoEnvio::desdeRespuesta($this->respuesta('EPR', 'Envio Procesado', [['39', 5, 4, 1, 0]]), '1');

        $this->assertTrue($estado->procesado());
        $this->assertFalse($estado->limpio());
    }

    public function test_rechazos_anulan_lo_limpio_aunque_todo_lo_informado_figure_aceptado(): void
    {
        $estado = EstadoEnvio::desdeRespuesta($this->respuesta('EPR', 'Envio Procesado', [['39', 5, 5, 1, 0]]), '1');

        $this->assertFalse($estado->limpio());
    }

    public function test_con_reparos_no_es_limpio(): void
    {
        $estado = EstadoEnvio::desdeRespuesta($this->respuesta('EPR', 'Envio Procesado', [['39', 5, 5, 0, 2]]), '1');

        $this->assertFalse($estado->limpio());
    }

    public function test_sin_documentos_informados_no_es_limpio(): void
    {
        $estado = EstadoEnvio::desdeRespuesta($this->respuesta('EPR', 'Envio Procesado'), '1');

        $this->assertFalse($estado->limpio());
    }

    public function test_suma_varios_tipos_de_documento(): void
    {
        $estado = EstadoEnvio::desdeRespuesta($this->respuesta('EPR', 'ok', [['39', 3, 3, 0, 0], ['41', 2, 2, 0, 0]]), '1');

        $this->assertSame(5, $estado->informados);
        $this->assertSame(5, $estado->aceptados);
        $this->assertTrue($estado->limpio());
    }

    public function test_estados_intermedios_siguen_en_proceso(): void
    {
        foreach (['REC', 'SOK', 'CRT', 'FOK', 'PRD'] as $codigo) {
            $estado = EstadoEnvio::desdeRespuesta($this->respuesta($codigo, 'validando'), '1');

            $this->assertTrue($estado->enProceso(), $codigo);
            $this->assertFalse($estado->procesado(), $codigo);
            $this->assertFalse($estado->limpio(), $codigo);
        }
    }

    public function test_acepta_la_respuesta_con_entidades_html(): void
    {
        $escapada = htmlspecialchars($this->respuesta('EPR', 'ok', [['39', 1, 1, 0, 0]]));

        $this->assertTrue(EstadoEnvio::desdeRespuesta($escapada, '1')->limpio());
    }

    public function test_sin_estado_lanza_excepcion(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('777');
        EstadoEnvio::desdeRespuesta('<SII:RESPUESTA></SII:RESPUESTA>', '777');
    }
}
