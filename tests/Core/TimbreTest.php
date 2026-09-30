<?php

namespace Winex\Certificacion\Tests\Core;

use PHPUnit\Framework\TestCase;
use Winex\Certificacion\Core\Timbre;
use Winex\Certificacion\Tests\Soporte\Criptografia;

final class TimbreTest extends TestCase
{
    private function datos(array $cambios = []): array
    {
        return array_merge([
            'rut_emisor' => '76123456-0',
            'tipo_dte' => '39',
            'folio' => '5',
            'fecha' => '2026-09-05',
            'rut_receptor' => '66666666-6',
            'razon_social_receptor' => 'Cliente General',
            'monto_total' => '1190',
            'nombre_item_1' => 'Producto de prueba',
        ], $cambios);
    }

    /** Separa el <DD> (bytes exactos que se firmaron) y la firma del <TED>. */
    private function partes(string $ted): array
    {
        $this->assertSame(1, preg_match('/^<TED version="1.0">(<DD>.*<\/DD>)<FRMT algoritmo="SHA1withRSA">([^<]+)<\/FRMT><\/TED>$/s', $ted, $m), 'Estructura de TED inesperada.');

        return [$m[1], base64_decode($m[2])];
    }

    public function test_la_firma_del_ted_es_verificable_con_la_clave_publica_del_caf(): void
    {
        $ted = (new Timbre(Criptografia::caf()))->generarTed($this->datos());
        [$dd, $firma] = $this->partes($ted);

        $this->assertSame(1, openssl_verify($dd, $firma, Criptografia::clavePublicaPem(), OPENSSL_ALGO_SHA1));
    }

    public function test_alterar_un_dato_invalida_la_firma(): void
    {
        [$dd, $firma] = $this->partes((new Timbre(Criptografia::caf()))->generarTed($this->datos()));
        $alterado = str_replace('<MNT>1190</MNT>', '<MNT>1</MNT>', $dd);

        $this->assertNotSame($dd, $alterado);
        $this->assertSame(0, openssl_verify($alterado, $firma, Criptografia::clavePublicaPem(), OPENSSL_ALGO_SHA1));
    }

    public function test_el_dd_no_lleva_blancos_entre_etiquetas(): void
    {
        // Regresion del reparo 510: el SII aplana los blancos antes de verificar.
        [$dd] = $this->partes((new Timbre(Criptografia::caf()))->generarTed($this->datos()));

        $this->assertDoesNotMatchRegularExpression('/>\s+</', $dd);
    }

    public function test_el_dd_trae_los_datos_del_documento_y_el_caf(): void
    {
        [$dd] = $this->partes((new Timbre(Criptografia::caf()))->generarTed($this->datos()));

        foreach (['<RE>76123456-0</RE>', '<TD>39</TD>', '<F>5</F>', '<FE>2026-09-05</FE>', '<RR>66666666-6</RR>', '<MNT>1190</MNT>', '<IT1>Producto de prueba</IT1>', '<CAF version="1.0">'] as $fragmento) {
            $this->assertStringContainsString($fragmento, $dd);
        }
        $this->assertMatchesRegularExpression('/<TSTED>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}<\/TSTED>/', $dd);
    }

    public function test_el_primer_item_se_trunca_a_40_caracteres(): void
    {
        [$dd] = $this->partes((new Timbre(Criptografia::caf()))->generarTed($this->datos(['nombre_item_1' => str_repeat('x', 60)])));

        $this->assertStringContainsString('<IT1>'.str_repeat('x', 40).'</IT1>', $dd);
    }

    public function test_los_caracteres_especiales_se_escapan(): void
    {
        [$dd] = $this->partes((new Timbre(Criptografia::caf()))->generarTed($this->datos(['razon_social_receptor' => 'Pan & Vino <SA>'])));

        $this->assertStringContainsString('<RSR>Pan &amp; Vino &lt;SA&gt;</RSR>', $dd);
    }

    public function test_el_ted_se_firma_sobre_los_bytes_iso_8859_1(): void
    {
        // Regresion del rechazo RFR con razones sociales con tilde: el DTE viaja
        // en ISO-8859-1 y el SII verifica la firma sobre esos bytes.
        $ted = (new Timbre(Criptografia::caf()))->generarTed($this->datos(['razon_social_receptor' => 'Muñoz Díaz']));
        [$dd, $firma] = $this->partes($ted);

        $this->assertSame(1, openssl_verify($dd, $firma, Criptografia::clavePublicaPem(), OPENSSL_ALGO_SHA1));
        $this->assertStringContainsString("Mu\xF1oz D\xEDaz", $dd, 'La ñ y la í deben ir como bytes ISO-8859-1.');
        $this->assertFalse(mb_check_encoding($dd, 'UTF-8'));
    }

    public function test_acepta_un_caf_con_tildes_en_iso_8859_1(): void
    {
        // Algunos CAF del SII traen la razon social en ISO-8859-1 sin declararlo.
        $caf = mb_convert_encoding(Criptografia::caf('76123456-0', 'DÍAZ Y MUÑOZ LTDA'), 'ISO-8859-1', 'UTF-8');

        $ted = (new Timbre($caf))->generarTed($this->datos());
        [$dd, $firma] = $this->partes($ted);

        $this->assertSame(1, openssl_verify($dd, $firma, Criptografia::clavePublicaPem(), OPENSSL_ALGO_SHA1));
    }

    public function test_carga_el_caf_desde_un_archivo(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'caf_');
        file_put_contents($ruta, Criptografia::caf());

        try {
            [$dd, $firma] = $this->partes((new Timbre($ruta))->generarTed($this->datos()));
            $this->assertSame(1, openssl_verify($dd, $firma, Criptografia::clavePublicaPem(), OPENSSL_ALGO_SHA1));
        } finally {
            @unlink($ruta);
        }
    }

    public function test_from_xml_equivale_al_constructor(): void
    {
        $ted = Timbre::fromXml(Criptografia::caf())->generarTed($this->datos());

        $this->assertStringStartsWith('<TED version="1.0">', $ted);
    }

    public function test_archivo_caf_inexistente(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No se encontró el archivo CAF');
        new Timbre(sys_get_temp_dir().'/no-existe-'.uniqid().'.xml');
    }

    public function test_caf_sin_nodo_caf(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('<CAF>');
        new Timbre('<AUTORIZACION><RSASK>x</RSASK></AUTORIZACION>');
    }

    public function test_caf_sin_llave_privada(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('RSASK');
        new Timbre('<AUTORIZACION><CAF version="1.0"><DA/></CAF></AUTORIZACION>');
    }
}
