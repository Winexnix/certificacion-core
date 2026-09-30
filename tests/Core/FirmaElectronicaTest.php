<?php

namespace Winex\Certificacion\Tests\Core;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Winex\Certificacion\Core\FirmaElectronica;
use Winex\Certificacion\Core\GeneradorBoleta;
use Winex\Certificacion\Tests\Soporte\Criptografia;

final class FirmaElectronicaTest extends TestCase
{
    private const DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    private function firmador(): FirmaElectronica
    {
        return new FirmaElectronica(Criptografia::llavePem(), Criptografia::certificadoPem());
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        $this->assertTrue($dom->loadXML($xml), 'El XML firmado no es valido.');
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('ds', self::DSIG);
        $xp->registerNamespace('s', 'http://www.sii.cl/SiiDte');

        return $xp;
    }

    private function boleta(): string
    {
        return (new GeneradorBoleta)->generarBoleta(
            ['rut' => '76123456-0', 'razon_social' => 'EMPRESA SPA', 'giro' => 'GIRO', 'direccion' => 'CALLE 1', 'comuna' => 'SANTIAGO'],
            ['rut' => '66666666-6', 'razon_social' => 'Cliente'],
            '56',
            [['nombre' => 'X', 'cantidad' => 1, 'precio' => 1190]],
        );
    }

    /** Verifica que la <SignatureValue> corresponde al <SignedInfo> canonicalizado. */
    private function firmaValida(DOMXPath $xp): bool
    {
        $signedInfo = $xp->query('//ds:Signature/ds:SignedInfo')->item(0);
        $firma = base64_decode($xp->query('//ds:Signature/ds:SignatureValue')->item(0)->nodeValue);

        return openssl_verify($signedInfo->C14N(), $firma, Criptografia::clavePublicaPem(), OPENSSL_ALGO_SHA1) === 1;
    }

    // --- firmarDte --------------------------------------------------------

    public function test_firmar_dte_genera_una_firma_verificable(): void
    {
        $xp = $this->xpath($this->firmador()->firmarDte($this->boleta(), '#F56T39'));

        $this->assertSame(1, $xp->query('//ds:Signature')->length);
        $this->assertTrue($this->firmaValida($xp), 'La SignatureValue no corresponde al SignedInfo.');
    }

    public function test_el_digest_corresponde_al_documento_firmado(): void
    {
        $xp = $this->xpath($this->firmador()->firmarDte($this->boleta(), '#F56T39'));

        $documento = $xp->query('//s:Documento')->item(0);
        $esperado = base64_encode(sha1($documento->C14N(), true));

        $this->assertSame($esperado, $xp->query('//ds:Reference/ds:DigestValue')->item(0)->nodeValue);
        $this->assertSame('#F56T39', $xp->query('//ds:Reference/@URI')->item(0)->nodeValue);
    }

    public function test_alterar_el_documento_despues_de_firmar_cambia_el_digest(): void
    {
        $firmado = $this->firmador()->firmarDte($this->boleta(), '#F56T39');
        $alterado = str_replace('<MntTotal>1190</MntTotal>', '<MntTotal>1</MntTotal>', $firmado);

        $this->assertNotSame($firmado, $alterado);

        $xp = $this->xpath($alterado);
        $recalculado = base64_encode(sha1($xp->query('//s:Documento')->item(0)->C14N(), true));

        $this->assertNotSame($recalculado, $xp->query('//ds:Reference/ds:DigestValue')->item(0)->nodeValue);
    }

    public function test_la_firma_incluye_el_certificado_limpio_y_la_clave_publica(): void
    {
        $xp = $this->xpath($this->firmador()->firmarDte($this->boleta(), '#F56T39'));

        $certificado = $xp->query('//ds:X509Certificate')->item(0)->nodeValue;
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $certificado);
        $this->assertStringNotContainsString("\n", $certificado);
        $this->assertNotFalse(openssl_x509_read("-----BEGIN CERTIFICATE-----\n".chunk_split($certificado, 64)."-----END CERTIFICATE-----\n"));

        $detalles = openssl_pkey_get_details(Criptografia::llave());
        $this->assertSame(base64_encode($detalles['rsa']['n']), $xp->query('//ds:Modulus')->item(0)->nodeValue);
        $this->assertSame(base64_encode($detalles['rsa']['e']), $xp->query('//ds:Exponent')->item(0)->nodeValue);
    }

    public function test_firmar_el_sobre_del_set_apunta_al_set_dte(): void
    {
        // El SignedInfo se firma declarando xmlns:xsi, igual que la raiz de los
        // documentos reales del SII; por eso los fixtures tambien lo declaran.
        $sobre = '<?xml version="1.0"?><EnvioBOLETA xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><SetDTE ID="SetDoc"><x>1</x></SetDTE></EnvioBOLETA>';
        $xp = $this->xpath($this->firmador()->firmarDte($sobre, '#SetDoc'));

        $setDte = $xp->query('//s:SetDTE')->item(0);
        $this->assertSame(base64_encode(sha1($setDte->C14N(), true)), $xp->query('//ds:DigestValue')->item(0)->nodeValue);
        $this->assertTrue($this->firmaValida($xp));
    }

    public function test_firmar_el_rcof_apunta_al_documento_de_consumo(): void
    {
        $rcof = '<?xml version="1.0"?><ConsumoFolios xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><DocumentoConsumoFolios ID="RCOF"><x>1</x></DocumentoConsumoFolios></ConsumoFolios>';
        $xp = $this->xpath($this->firmador()->firmarDte($rcof, '#RCOF'));

        $nodo = $xp->query('//s:DocumentoConsumoFolios')->item(0);
        $this->assertSame(base64_encode(sha1($nodo->C14N(), true)), $xp->query('//ds:DigestValue')->item(0)->nodeValue);
        $this->assertTrue($this->firmaValida($xp));
    }

    public function test_firmar_sin_el_nodo_esperado_lanza_excepcion(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No se encontró el nodo SetDTE');
        $this->firmador()->firmarDte('<otra><cosa/></otra>', '#SetDoc');
    }

    // --- firmarSemilla ----------------------------------------------------

    public function test_firmar_semilla_usa_el_transform_enveloped_signature(): void
    {
        // Regresion del ESTADO 11 "elemento 'Certificate' no existe": con el
        // transform C14N en vez de enveloped-signature el SII rechaza el canje.
        $xp = $this->xpath($this->firmador()->firmarSemilla('123456789012'));

        $this->assertSame('', $xp->query('//ds:Reference/@URI')->item(0)->nodeValue);
        $transforms = [];
        foreach ($xp->query('//ds:Reference/ds:Transforms/ds:Transform/@Algorithm') as $algoritmo) {
            $transforms[] = $algoritmo->nodeValue;
        }
        $this->assertSame([self::DSIG.'enveloped-signature'], $transforms);
    }

    public function test_firmar_semilla_es_verificable(): void
    {
        $xml = $this->firmador()->firmarSemilla('123456789012');
        $xp = $this->xpath($xml);

        $this->assertTrue($this->firmaValida($xp));

        // El digest se calcula sobre el documento SIN la firma (enveloped).
        $original = new DOMDocument;
        $original->loadXML('<getToken><item><Semilla>123456789012</Semilla></item></getToken>');
        $this->assertSame(
            base64_encode(sha1($original->documentElement->C14N(), true)),
            $xp->query('//ds:DigestValue')->item(0)->nodeValue,
        );
    }

    public function test_firmar_semilla_mantiene_la_semilla_y_el_certificado(): void
    {
        $xml = $this->firmador()->firmarSemilla('987654321098');
        $xp = $this->xpath($xml);

        $this->assertSame('987654321098', $xp->query('//Semilla')->item(0)->nodeValue);
        $this->assertSame(1, $xp->query('//ds:X509Certificate')->length);
        $this->assertStringStartsWith('<?xml version="1.0"?><getToken>', $xml);
        $this->assertStringEndsWith('</getToken>', $xml);
    }
}
