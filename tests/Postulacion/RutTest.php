<?php

namespace Winex\Certificacion\Tests\Postulacion;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Winex\Certificacion\Postulacion\PostulacionException;
use Winex\Certificacion\Postulacion\Rut;

final class RutTest extends TestCase
{
    #[DataProvider('formatosValidos')]
    public function test_acepta_los_formatos_habituales(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, (string) Rut::de($entrada));
    }

    public static function formatosValidos(): array
    {
        return [
            'con guion' => ['76123456-0', '76123456-0'],
            'con puntos' => ['76.123.456-0', '76123456-0'],
            'sin separadores' => ['761234560', '76123456-0'],
            'DV numerico' => ['12345678-5', '12345678-5'],
            'DV K en mayuscula' => ['60803000-K', '60803000-K'],
            'DV k en minuscula' => ['60803000-k', '60803000-K'],
            'RUT generico de boletas' => ['66666666-6', '66666666-6'],
        ];
    }

    #[DataProvider('rutsInvalidos')]
    public function test_rechaza_rut_invalido(string $entrada): void
    {
        $this->expectException(PostulacionException::class);
        Rut::de($entrada);
    }

    public static function rutsInvalidos(): array
    {
        return [
            'DV equivocado' => ['76123456-9'],
            'vacio' => [''],
            'solo un caracter' => ['7'],
            'letras en el numero' => ['7A123456-0'],
            'demasiado largo' => ['7612345678-0'],
            'solo el DV' => ['K'],
        ];
    }

    public function test_el_mensaje_incluye_el_nombre_del_campo(): void
    {
        $this->expectException(PostulacionException::class);
        $this->expectExceptionMessage('RUT de la empresa');
        Rut::de('76123456-9', 'RUT de la empresa');
    }
}
