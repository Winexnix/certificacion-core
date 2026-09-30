<?php

namespace Winex\Certificacion\Tests\Core;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Winex\Certificacion\Core\GeneradorBoleta;

final class GeneradorBoletaTest extends TestCase
{
    private const EMISOR = [
        'rut' => '76123456-0',
        'razon_social' => 'EMPRESA DE PRUEBA SPA',
        'giro' => 'ACTIVIDADES DE CONSULTORIA DE INFORMATICA',
        'direccion' => 'CALLE 123',
        'comuna' => 'SANTIAGO',
    ];

    private const RECEPTOR = ['rut' => '66666666-6', 'razon_social' => 'Cliente General'];

    private function generar(array $detalle, ?array $referencia = null, ?array $descuento = null, string $tipo = '39'): DOMXPath
    {
        $xml = (new GeneradorBoleta)->generarBoleta(self::EMISOR, self::RECEPTOR, '56', $detalle, $tipo, $referencia, $descuento);

        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'La boleta generada no es XML valido.');
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('s', 'http://www.sii.cl/SiiDte');

        return $xp;
    }

    private function valor(DOMXPath $xp, string $ruta): ?string
    {
        return $xp->query($ruta)->item(0)?->nodeValue;
    }

    private function totales(DOMXPath $xp): array
    {
        $t = [];
        foreach (['MntNeto', 'MntExe', 'IVA', 'MntTotal'] as $campo) {
            $t[$campo] = $this->valor($xp, "//s:Totales/s:{$campo}");
        }

        return $t;
    }

    public function test_identificacion_emisor_y_receptor(): void
    {
        $xp = $this->generar([['nombre' => 'Producto', 'cantidad' => 1, 'precio' => 1190]]);

        $this->assertSame('F56T39', $xp->query('//s:Documento/@ID')->item(0)->nodeValue);
        $this->assertSame('39', $this->valor($xp, '//s:IdDoc/s:TipoDTE'));
        $this->assertSame('56', $this->valor($xp, '//s:IdDoc/s:Folio'));
        $this->assertSame(date('Y-m-d'), $this->valor($xp, '//s:IdDoc/s:FchEmis'));
        $this->assertSame('76123456-0', $this->valor($xp, '//s:Emisor/s:RUTEmisor'));
        $this->assertSame('SANTIAGO', $this->valor($xp, '//s:Emisor/s:CmnaOrigen'));
        $this->assertSame('66666666-6', $this->valor($xp, '//s:Receptor/s:RUTRecep'));
    }

    public function test_precio_con_iva_incluido_se_desglosa_en_neto_e_iva(): void
    {
        // Regresion: la boleta tipo 39 debe llevar MntNeto + IVA, no solo el total.
        $t = $this->totales($this->generar([['nombre' => 'Producto', 'cantidad' => 1, 'precio' => 1190]]));

        $this->assertSame('1000', $t['MntNeto']);
        $this->assertSame('190', $t['IVA']);
        $this->assertSame('1190', $t['MntTotal']);
        $this->assertNull($t['MntExe']);
    }

    public function test_neto_mas_iva_siempre_cuadra_con_el_bruto(): void
    {
        foreach ([1, 7, 100, 999, 1590, 12720, 29800, 123457] as $bruto) {
            $t = $this->totales($this->generar([['nombre' => 'X', 'cantidad' => 1, 'precio' => $bruto]]));

            $this->assertSame($bruto, (int) $t['MntNeto'] + (int) $t['IVA'], "bruto {$bruto}");
            $this->assertSame((string) $bruto, $t['MntTotal']);
        }
    }

    public function test_cantidad_multiplica_el_precio(): void
    {
        $t = $this->totales($this->generar([['nombre' => 'Papel', 'cantidad' => 17, 'precio' => 120]]));

        $this->assertSame('2040', $t['MntTotal']);
    }

    public function test_item_exento_va_en_mntexe_y_sin_iva(): void
    {
        $xp = $this->generar([['nombre' => 'Libro', 'cantidad' => 1, 'precio' => 5000, 'exento' => true]]);
        $t = $this->totales($xp);

        $this->assertSame('5000', $t['MntExe']);
        $this->assertSame('5000', $t['MntTotal']);
        $this->assertNull($t['MntNeto']);
        $this->assertNull($t['IVA']);
        $this->assertSame('1', $this->valor($xp, '//s:Detalle/s:IndExe'));
    }

    public function test_afecto_y_exento_juntos_caso_4_del_set(): void
    {
        $t = $this->totales($this->generar([
            ['nombre' => 'item afecto 1', 'cantidad' => 8, 'precio' => 1590],
            ['nombre' => 'item exento 2', 'cantidad' => 2, 'precio' => 1000, 'exento' => true],
        ]));

        $this->assertSame('10689', $t['MntNeto']);
        $this->assertSame('2031', $t['IVA']);
        $this->assertSame('2000', $t['MntExe']);
        $this->assertSame('14720', $t['MntTotal']);
    }

    public function test_orden_de_los_totales_respeta_el_esquema(): void
    {
        $xp = $this->generar([
            ['nombre' => 'A', 'cantidad' => 1, 'precio' => 1190],
            ['nombre' => 'B', 'cantidad' => 1, 'precio' => 500, 'exento' => true],
        ]);

        $orden = [];
        foreach ($xp->query('//s:Totales/*') as $nodo) {
            $orden[] = $nodo->localName;
        }

        $this->assertSame(['MntNeto', 'MntExe', 'IVA', 'MntTotal'], $orden);
    }

    public function test_unidad_de_medida(): void
    {
        $xp = $this->generar([['nombre' => 'Arroz', 'cantidad' => 5, 'precio' => 700, 'unidad' => 'Kg']]);

        $this->assertSame('Kg', $this->valor($xp, '//s:Detalle/s:UnmdItem'));
    }

    public function test_referencia_al_caso_del_set_de_pruebas(): void
    {
        $xp = $this->generar(
            [['nombre' => 'X', 'cantidad' => 1, 'precio' => 1000]],
            ['codigo' => 'SET', 'razon' => 'CASO-1'],
        );

        $this->assertSame('SET', $this->valor($xp, '//s:Referencia/s:CodRef'));
        $this->assertSame('CASO-1', $this->valor($xp, '//s:Referencia/s:RazonRef'));
    }

    public function test_sin_referencia_no_hay_nodo(): void
    {
        $xp = $this->generar([['nombre' => 'X', 'cantidad' => 1, 'precio' => 1000]]);

        $this->assertSame(0, $xp->query('//s:Referencia')->length);
    }

    public function test_los_caracteres_especiales_quedan_bien_escapados(): void
    {
        $xp = $this->generar([['nombre' => 'Pan & <Queso> "Extra"', 'cantidad' => 1, 'precio' => 1000]]);

        $this->assertSame('Pan & <Queso> "Extra"', $this->valor($xp, '//s:Detalle/s:NmbItem'));
    }

    public function test_el_xml_declara_iso_8859_1(): void
    {
        $xml = (new GeneradorBoleta)->generarBoleta(self::EMISOR, self::RECEPTOR, '1', [['nombre' => 'X', 'cantidad' => 1, 'precio' => 100]]);

        $this->assertStringContainsString('encoding="ISO-8859-1"', $xml);
    }

    // --- Descuento global -------------------------------------------------

    /** Suma de los MontoItem de las lineas (lo que el SII contrasta contra MntTotal). */
    private function sumaDeLineas(DOMXPath $xp): int
    {
        $suma = 0;
        foreach ($xp->query('//s:Detalle/s:MontoItem') as $nodo) {
            $suma += (int) $nodo->nodeValue;
        }

        return $suma;
    }

    public function test_descuento_en_porcentaje(): void
    {
        $xp = $this->generar([['nombre' => 'X', 'cantidad' => 1, 'precio' => 10000]], null, ['tipo' => 'porcentaje', 'valor' => 10]);

        $this->assertSame('9000', $this->valor($xp, '//s:Totales/s:MntTotal'));
        $this->assertSame('1000', $this->valor($xp, '//s:Detalle/s:DescuentoMonto'));
    }

    public function test_descuento_en_monto(): void
    {
        $xp = $this->generar([['nombre' => 'X', 'cantidad' => 1, 'precio' => 10000]], null, ['tipo' => 'monto', 'valor' => 2500]);

        $this->assertSame('7500', $this->valor($xp, '//s:Totales/s:MntTotal'));
    }

    public function test_el_total_siempre_iguala_la_suma_de_las_lineas_con_descuento(): void
    {
        // Regresion del reparo 260 "Monto Total No Cuadra con Parciales": el
        // descuento debe bajar las lineas, no declararse aparte.
        $detalle = [
            ['nombre' => 'A', 'cantidad' => 3, 'precio' => 1333],
            ['nombre' => 'B', 'cantidad' => 1, 'precio' => 777],
            ['nombre' => 'C', 'cantidad' => 2, 'precio' => 951, 'exento' => true],
        ];

        foreach ([['porcentaje', 7], ['porcentaje', 33.3], ['monto', 1], ['monto', 999], ['monto', 4775]] as [$tipo, $valor]) {
            $xp = $this->generar($detalle, null, ['tipo' => $tipo, 'valor' => $valor]);

            $this->assertSame(
                $this->sumaDeLineas($xp),
                (int) $this->valor($xp, '//s:Totales/s:MntTotal'),
                "descuento {$tipo} {$valor}",
            );
        }
    }

    public function test_el_descuento_no_toca_los_items_exentos(): void
    {
        $xp = $this->generar(
            [
                ['nombre' => 'Afecto', 'cantidad' => 1, 'precio' => 1000],
                ['nombre' => 'Exento', 'cantidad' => 1, 'precio' => 500, 'exento' => true],
            ],
            null,
            ['tipo' => 'porcentaje', 'valor' => 50],
        );

        $this->assertSame('500', $this->valor($xp, '//s:Totales/s:MntExe'));
        $this->assertSame('1000', $this->valor($xp, '//s:Totales/s:MntTotal'));
    }

    public function test_el_descuento_nunca_deja_el_afecto_en_negativo(): void
    {
        $xp = $this->generar([['nombre' => 'X', 'cantidad' => 1, 'precio' => 1000]], null, ['tipo' => 'monto', 'valor' => 999999]);

        $this->assertSame('0', $this->valor($xp, '//s:Totales/s:MntTotal'));
    }

    public function test_un_porcentaje_mayor_a_100_se_acota(): void
    {
        $xp = $this->generar([['nombre' => 'X', 'cantidad' => 1, 'precio' => 1000]], null, ['tipo' => 'porcentaje', 'valor' => 250]);

        $this->assertSame('0', $this->valor($xp, '//s:Totales/s:MntTotal'));
    }

    public function test_descuento_cero_o_negativo_se_ignora(): void
    {
        foreach ([0, -5] as $valor) {
            $xp = $this->generar([['nombre' => 'X', 'cantidad' => 1, 'precio' => 1000]], null, ['tipo' => 'monto', 'valor' => $valor]);

            $this->assertSame('1000', $this->valor($xp, '//s:Totales/s:MntTotal'));
            $this->assertSame(0, $xp->query('//s:DescuentoMonto')->length);
        }
    }
}
