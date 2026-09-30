<?php

namespace Winex\Certificacion\Tests\Core;

use PHPUnit\Framework\TestCase;
use Winex\Certificacion\Core\Certificate;
use Winex\Certificacion\Tests\Soporte\Criptografia;

final class CertificateTest extends TestCase
{
    private const CLAVE = 'clave-de-prueba';

    /** @var list<string> */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
    }

    private function archivo(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'crt_');
        file_put_contents($ruta, $contenido);

        return $this->temporales[] = $ruta;
    }

    public function test_carga_un_pfx_desde_sus_bytes(): void
    {
        $cert = Certificate::fromContents(Criptografia::pfx(self::CLAVE), self::CLAVE);

        $this->assertStringContainsString('PRIVATE KEY', $cert->getPrivateKey());
        $this->assertStringContainsString('BEGIN CERTIFICATE', $cert->getPublicKey());
    }

    public function test_carga_un_pfx_desde_un_archivo(): void
    {
        $cert = new Certificate($this->archivo(Criptografia::pfx(self::CLAVE)), self::CLAVE);

        $this->assertStringContainsString('BEGIN CERTIFICATE', $cert->getPublicKey());
    }

    public function test_la_llave_cargada_es_la_misma_del_certificado(): void
    {
        $cert = Certificate::fromContents(Criptografia::pfx(self::CLAVE), self::CLAVE);

        $this->assertTrue(openssl_x509_check_private_key($cert->getPublicKey(), $cert->getPrivateKey()));
    }

    public function test_carga_desde_un_pem(): void
    {
        $pem = Criptografia::llavePem().Criptografia::certificadoPem();
        $cert = Certificate::fromContents($pem, '');

        $this->assertTrue(openssl_x509_check_private_key($cert->getPublicKey(), $cert->getPrivateKey()));
    }

    public function test_un_pem_con_saltos_de_linea_de_windows_queda_identico(): void
    {
        $pem = Criptografia::llavePem().Criptografia::certificadoPem();

        $unix = Certificate::fromContents($pem, '');
        $windows = Certificate::fromContents(str_replace("\n", "\r\n", $pem), '');

        $this->assertSame($unix->getPublicKey(), $windows->getPublicKey());
        $this->assertStringNotContainsString("\r", $windows->getPublicKey());
    }

    public function test_to_pem_puede_volver_a_cargarse(): void
    {
        $original = Certificate::fromContents(Criptografia::pfx(self::CLAVE), self::CLAVE);
        $recargado = Certificate::fromContents($original->toPem(), '');

        $this->assertSame(trim($original->getPublicKey()), trim($recargado->getPublicKey()));
        $this->assertTrue(openssl_x509_check_private_key($recargado->getPublicKey(), $recargado->getPrivateKey()));
    }

    public function test_to_pem_pone_la_llave_primero_y_luego_el_certificado(): void
    {
        $pem = Certificate::fromContents(Criptografia::pfx(self::CLAVE), self::CLAVE)->toPem();

        $this->assertLessThan(strpos($pem, 'BEGIN CERTIFICATE'), strpos($pem, 'PRIVATE KEY'));
    }

    public function test_info_entrega_cn_y_vigencia(): void
    {
        $info = Certificate::fromContents(Criptografia::pfx(self::CLAVE), self::CLAVE)->getInfo();

        $this->assertSame('Titular de Prueba', $info['cn']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $info['not_before']);
        $this->assertGreaterThan($info['not_before'], $info['not_after']);
    }

    public function test_la_cadena_sin_intermedios_es_la_propia_hoja(): void
    {
        $cert = Certificate::fromContents(Criptografia::pfx(self::CLAVE), self::CLAVE);

        $this->assertSame($cert->getPublicKey(), $cert->getPublicKeyChain());
    }

    public function test_archivo_inexistente(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('no existe');
        new Certificate(sys_get_temp_dir().'/no-existe-'.uniqid().'.pfx', 'x');
    }

    public function test_pem_sin_llave_privada(): void
    {
        $this->expectException(\Exception::class);
        Certificate::fromContents(Criptografia::certificadoPem(), '');
    }

    public function test_pem_sin_certificado(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('CERTIFICATE');
        Certificate::fromContents(Criptografia::llavePem(), '');
    }

    // --- Utilidades de seguridad -----------------------------------------

    public function test_sin_material_pem_oculta_llaves_y_certificados(): void
    {
        $salida = "Error al leer\n-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASC\n-----END PRIVATE KEY-----\nfin";

        $limpia = Certificate::sinMaterialPem($salida);

        $this->assertStringNotContainsString('MIIEvQ', $limpia);
        $this->assertStringContainsString('[PEM omitido]', $limpia);
        $this->assertStringContainsString('Error al leer', $limpia);
    }

    public function test_sin_material_pem_oculta_un_bloque_cortado(): void
    {
        $limpia = Certificate::sinMaterialPem("x\n-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASC");

        $this->assertStringNotContainsString('MIIEvQ', $limpia);
    }

    public function test_la_clave_nunca_va_en_los_argumentos_del_comando(): void
    {
        // Un -passin pass:CLAVE se ve en `ps` y en /proc/<pid>/cmdline.
        foreach (['env:WINEX_PFX_PASS', 'file:/tmp/x'] as $origen) {
            $comando = Certificate::comandoPkcs12('openssl', ['-nocerts'], true, $origen);

            $this->assertNotContains('pass:'.self::CLAVE, $comando);
            foreach ($comando as $argumento) {
                $this->assertStringNotContainsString(self::CLAVE, $argumento);
            }
            $this->assertContains($origen, $comando);
        }
    }

    public function test_el_comando_pide_el_proveedor_legacy_solo_cuando_corresponde(): void
    {
        $this->assertContains('-legacy', Certificate::comandoPkcs12('openssl', [], true, 'env:X'));
        $this->assertNotContains('-legacy', Certificate::comandoPkcs12('openssl', [], false, 'env:X'));
    }
}
