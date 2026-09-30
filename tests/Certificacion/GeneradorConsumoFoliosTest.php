<?php

namespace Winex\Certificacion\Tests\Certificacion;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Winex\Certificacion\Certificacion\GeneradorConsumoFolios;

final class GeneradorConsumoFoliosTest extends TestCase
{
    private function generar(array $resumenOverride = [], array $caratulaOverride = []): DOMXPath
    {
        $caratula = array_merge([
            'rut_emisor' => '76123456-0',
            'rut_envia' => '12345678-5',
            'fch_resol' => '2026-09-04',
            'nro_resol' => '0',
            'fch_inicio' => '2026-09-05',
            'fch_final' => '2026-09-05',
            'sec_envio' => 1,
        ], $caratulaOverride);

        $resumen = array_merge([
            'tipo' => '39',
            'neto' => 10000,
            'iva' => 1900,
            'tasa_iva' => '19.00',
            'exento' => 0,
            'total' => 11900,
            'folios_emitidos' => 5,
            'folios_anulados' => 0,
            'folios_utilizados' => 5,
            'rangos_utilizados' => [['inicial' => 56, 'final' => 60]],
            'rangos_anulados' => [],
        ], $resumenOverride);

        $xml = (new GeneradorConsumoFolios)->generar($caratula, [$resumen]);

        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'El RCOF generado no es XML valido.');
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('s', 'http://www.sii.cl/SiiDte');

        return $xp;
    }

    private function valor(DOMXPath $xp, string $ruta): ?string
    {
        $nodo = $xp->query($ruta)->item(0);

        return $nodo?->nodeValue;
    }

    public function test_estructura_y_caratula(): void
    {
        $xp = $this->generar();

        $this->assertSame('RCOF', $xp->query('//s:DocumentoConsumoFolios/@ID')->item(0)->nodeValue);
        $this->assertSame('76123456-0', $this->valor($xp, '//s:Caratula/s:RutEmisor'));
        $this->assertSame('12345678-5', $this->valor($xp, '//s:Caratula/s:RutEnvia'));
        $this->assertSame('2026-09-04', $this->valor($xp, '//s:Caratula/s:FchResol'));
        $this->assertSame('1', $this->valor($xp, '//s:Caratula/s:SecEnvio'));
    }

    public function test_resumen_con_montos_y_rango(): void
    {
        $xp = $this->generar();

        $this->assertSame('39', $this->valor($xp, '//s:Resumen/s:TipoDocumento'));
        $this->assertSame('10000', $this->valor($xp, '//s:Resumen/s:MntNeto'));
        $this->assertSame('1900', $this->valor($xp, '//s:Resumen/s:MntIva'));
        $this->assertSame('19.00', $this->valor($xp, '//s:Resumen/s:TasaIVA'));
        $this->assertSame('11900', $this->valor($xp, '//s:Resumen/s:MntTotal'));
        $this->assertSame('56', $this->valor($xp, '//s:RangoUtilizados/s:Inicial'));
        $this->assertSame('60', $this->valor($xp, '//s:RangoUtilizados/s:Final'));
    }

    public function test_omite_neto_iva_y_exento_en_cero(): void
    {
        $xp = $this->generar(['neto' => 0, 'iva' => 0, 'exento' => 0, 'total' => 0]);

        $this->assertNull($this->valor($xp, '//s:Resumen/s:MntNeto'));
        $this->assertNull($this->valor($xp, '//s:Resumen/s:MntIva'));
        $this->assertNull($this->valor($xp, '//s:Resumen/s:TasaIVA'));
        $this->assertNull($this->valor($xp, '//s:Resumen/s:MntExento'));
        $this->assertSame('0', $this->valor($xp, '//s:Resumen/s:MntTotal'));
    }

    public function test_informa_el_monto_exento(): void
    {
        $xp = $this->generar(['exento' => 2000, 'total' => 13900]);

        $this->assertSame('2000', $this->valor($xp, '//s:Resumen/s:MntExento'));
    }

    public function test_rangos_anulados(): void
    {
        $xp = $this->generar(['folios_anulados' => 1, 'rangos_anulados' => [['inicial' => 58, 'final' => 58]]]);

        $this->assertSame('58', $this->valor($xp, '//s:RangoAnulados/s:Inicial'));
    }

    public function test_el_orden_de_los_elementos_del_resumen_respeta_el_esquema(): void
    {
        $xp = $this->generar(['exento' => 2000, 'total' => 13900]);

        $orden = [];
        foreach ($xp->query('//s:Resumen/*') as $nodo) {
            $orden[] = $nodo->localName;
        }

        $this->assertSame(
            ['TipoDocumento', 'MntNeto', 'MntIva', 'TasaIVA', 'MntExento', 'MntTotal', 'FoliosEmitidos', 'FoliosAnulados', 'FoliosUtilizados', 'RangoUtilizados'],
            $orden,
        );
    }
}
