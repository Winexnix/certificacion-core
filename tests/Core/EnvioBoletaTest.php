<?php

namespace Winex\Certificacion\Tests\Core;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Winex\Certificacion\Core\EnvioBoleta;
use Winex\Certificacion\Core\FirmaElectronica;
use Winex\Certificacion\Core\GeneradorBoleta;
use Winex\Certificacion\Tests\Soporte\Criptografia;

final class EnvioBoletaTest extends TestCase
{
    private function dte(string $folio, string $tipo = '39'): string
    {
        return (new GeneradorBoleta)->generarBoleta(
            ['rut' => '76123456-0', 'razon_social' => 'EMPRESA SPA', 'giro' => 'GIRO', 'direccion' => 'CALLE 1', 'comuna' => 'SANTIAGO'],
            ['rut' => '66666666-6', 'razon_social' => 'Cliente'],
            $folio,
            [['nombre' => 'X', 'cantidad' => 1, 'precio' => 1190]],
            $tipo,
        );
    }

    private function empaquetar(string $dtes): DOMXPath
    {
        $firmador = new FirmaElectronica(Criptografia::llavePem(), Criptografia::certificadoPem());
        $sobre = (new EnvioBoleta($firmador, '76123456-0', '12345678-5', '0', '2026-09-04'))->empaquetar($dtes);

        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($sobre), 'El sobre no es XML valido.');
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('s', 'http://www.sii.cl/SiiDte');
        $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        return $xp;
    }

    public function test_caratula(): void
    {
        $xp = $this->empaquetar($this->dte('56'));

        $this->assertSame('EnvioBOLETA', $xp->query('/*')->item(0)->localName);
        $this->assertSame('76123456-0', $xp->query('//s:Caratula/s:RutEmisor')->item(0)->nodeValue);
        $this->assertSame('12345678-5', $xp->query('//s:Caratula/s:RutEnvia')->item(0)->nodeValue);
        $this->assertSame('60803000-K', $xp->query('//s:Caratula/s:RutReceptor')->item(0)->nodeValue);
        $this->assertSame('2026-09-04', $xp->query('//s:Caratula/s:FchResol')->item(0)->nodeValue);
        $this->assertSame('0', $xp->query('//s:Caratula/s:NroResol')->item(0)->nodeValue);
    }

    public function test_cuenta_los_documentos_por_tipo(): void
    {
        $xp = $this->empaquetar($this->dte('1').$this->dte('2').$this->dte('3', '41'));

        $sub = [];
        foreach ($xp->query('//s:SubTotDTE') as $nodo) {
            $sub[$xp->query('s:TpoDTE', $nodo)->item(0)->nodeValue] = (int) $xp->query('s:NroDTE', $nodo)->item(0)->nodeValue;
        }

        $this->assertSame(['39' => 2, '41' => 1], $sub);
        $this->assertSame(3, $xp->query('//s:SetDTE/s:DTE')->length);
    }

    public function test_el_sobre_queda_firmado_sobre_el_set_dte(): void
    {
        $xp = $this->empaquetar($this->dte('56'));

        $this->assertSame('#SetDoc', $xp->query('//ds:Signature/ds:SignedInfo/ds:Reference/@URI')->item(0)->nodeValue);

        $setDte = $xp->query('//s:SetDTE')->item(0);
        $this->assertSame(
            base64_encode(sha1($setDte->C14N(), true)),
            $xp->query('//ds:Signature/ds:SignedInfo/ds:Reference/ds:DigestValue')->item(0)->nodeValue,
        );
    }

    public function test_quita_la_declaracion_xml_de_cada_dte(): void
    {
        $xp = $this->empaquetar($this->dte('56'));

        $this->assertSame(1, $xp->query('//s:SetDTE/s:DTE')->length);
    }
}
