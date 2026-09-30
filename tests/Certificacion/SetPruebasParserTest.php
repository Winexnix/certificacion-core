<?php

namespace Winex\Certificacion\Tests\Certificacion;

use PHPUnit\Framework\TestCase;
use Winex\Certificacion\Certificacion\SetPruebasParser;

final class SetPruebasParserTest extends TestCase
{
    private const SET = <<<'TXT'
SII SET DE PRUEBA DE BOLETA ELECTRONICA DE VENTAS Y SERVICIOS

CASO-1
==========

Item					Cantidad	Precio Unitario con IVA
Cambio de aceite			1			19900
Alineacion y balanceo 			1			9900


CASO-2
=========

Item					Cantidad	Precio Unitario con IVA
Papel de regalo				17			120


CASO-4
=========

Item					Cantidad	Precio Unitario con IVA
item afecto 1				8			1590
item exento 2				2			1000


OBSERVACION: "El item 1 es un servicio afecto. El item 2 es un servicio exento."


CASO-5
=========

Item					Cantidad	Precio Unitario con IVA
Arroz					5			700


OBSERVACION: "Se debe informar en el XML Unidad de medida en Kg."

==========================================================================

OBSERVACIONES GENERALES
TXT;

    private string $archivo;

    protected function setUp(): void
    {
        $this->archivo = tempnam(sys_get_temp_dir(), 'set_');
    }

    protected function tearDown(): void
    {
        @unlink($this->archivo);
    }

    private function parsear(string $contenido, string $tipo = '39'): array
    {
        file_put_contents($this->archivo, $contenido);

        return (new SetPruebasParser)->parsear($this->archivo, $tipo);
    }

    public function test_lee_todos_los_casos_en_orden(): void
    {
        $casos = $this->parsear(self::SET);

        $this->assertCount(4, $casos);
        $this->assertSame(['CASO-1', 'CASO-2', 'CASO-4', 'CASO-5'], array_column(array_column($casos, 'ref'), 'razon'));
        $this->assertSame('SET', $casos[0]['ref']['codigo']);
        $this->assertSame('39', $casos[0]['tipo']);
    }

    public function test_lee_items_cantidades_y_precios(): void
    {
        $casos = $this->parsear(self::SET);

        $this->assertSame(
            [
                ['nombre' => 'Cambio de aceite', 'cantidad' => 1, 'precio' => 19900],
                ['nombre' => 'Alineacion y balanceo', 'cantidad' => 1, 'precio' => 9900],
            ],
            $casos[0]['det'],
        );
        $this->assertSame(17, $casos[1]['det'][0]['cantidad']);
    }

    public function test_marca_como_exento_solo_el_item_indicado(): void
    {
        $det = $this->parsear(self::SET)[2]['det'];

        $this->assertArrayNotHasKey('exento', $det[0]);
        $this->assertTrue($det[1]['exento']);
    }

    public function test_aplica_la_unidad_de_medida_de_la_observacion(): void
    {
        $det = $this->parsear(self::SET)[3]['det'];

        $this->assertSame('Kg', $det[0]['unidad']);
    }

    public function test_acepta_saltos_de_linea_de_windows(): void
    {
        $casos = $this->parsear(str_replace("\n", "\r\n", self::SET));

        $this->assertCount(4, $casos);
        $this->assertSame(19900, $casos[0]['det'][0]['precio']);
    }

    public function test_separador_de_miles_en_precios(): void
    {
        $casos = $this->parsear("CASO-1\n=====\n\nItem\t\tCantidad\tPrecio\nComputador\t\t2\t\t1.250.000\n");

        $this->assertSame(1250000, $casos[0]['det'][0]['precio']);
    }

    public function test_el_tipo_de_documento_se_propaga(): void
    {
        $this->assertSame('41', $this->parsear(self::SET, '41')[0]['tipo']);
    }

    public function test_archivo_inexistente(): void
    {
        $this->expectException(\Exception::class);
        (new SetPruebasParser)->parsear(sys_get_temp_dir().'/no-existe-'.uniqid().'.txt');
    }

    public function test_archivo_sin_casos(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No se encontraron casos');
        $this->parsear("texto cualquiera\nsin casos\n");
    }

    public function test_caso_sin_items_legibles(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('CASO-1');
        $this->parsear("CASO-1\n=====\n\nItem\t\tCantidad\tPrecio\n(nada)\n");
    }

    public function test_parsea_el_set_de_ejemplo_que_trae_el_repositorio(): void
    {
        $casos = (new SetPruebasParser)->parsear(__DIR__.'/../../data/set_pruebas/Set Prueba BE.txt');

        $this->assertCount(5, $casos);
    }
}
