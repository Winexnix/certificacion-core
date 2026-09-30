<?php

namespace Winex\Certificacion\Tests\Postulacion;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Winex\Certificacion\Postulacion\DatosPostulacion;
use Winex\Certificacion\Postulacion\PostulacionException;

final class DatosPostulacionTest extends TestCase
{
    private function crear(array $cambios = []): DatosPostulacion
    {
        $datos = array_merge([
            'rutEmpresa' => '76123456-0',
            'rutAdministrador' => '12345678-5',
            'correoAdministrador' => 'admin@empresa.cl',
            'correoContactoSii' => 'contacto@empresa.cl',
            'correoIntercambio' => 'intercambio@empresa.cl',
            'nombreSoftware' => 'Mi Software',
        ], $cambios);

        return new DatosPostulacion(...$datos);
    }

    public function test_datos_validos(): void
    {
        $datos = $this->crear();

        $this->assertSame('76123456-0', (string) $datos->empresa);
        $this->assertSame('12345678-5', (string) $datos->administrador);
        $this->assertTrue($datos->boletaExenta);
    }

    public function test_rut_de_empresa_invalido(): void
    {
        $this->expectException(PostulacionException::class);
        $this->crear(['rutEmpresa' => '76123456-1']);
    }

    #[DataProvider('correosInvalidos')]
    public function test_correo_invalido(string $correo): void
    {
        $this->expectException(PostulacionException::class);
        $this->crear(['correoAdministrador' => $correo]);
    }

    public static function correosInvalidos(): array
    {
        return [
            'sin arroba' => ['adminempresa.cl'],
            'dos arrobas' => ['a@b@empresa.cl'],
            'con mas (+)' => ['admin+sii@empresa.cl'],
            'con tilde' => ['administración@empresa.cl'],
            'con espacio' => ['admin @empresa.cl'],
            'sin punto en el dominio' => ['admin@empresa'],
            'punto pegado al arroba' => ['admin@.cl'],
            'empieza con punto' => ['.admin@empresa.cl'],
            'termina con punto' => ['admin@empresa.cl.'],
            'mas de 50 caracteres' => [str_repeat('a', 45).'@empresa.cl'],
        ];
    }

    public function test_nombre_de_software_obligatorio_y_acotado(): void
    {
        foreach (['', '   ', str_repeat('x', 31)] as $nombre) {
            try {
                $this->crear(['nombreSoftware' => $nombre]);
                $this->fail("Debio rechazar el nombre '{$nombre}'.");
            } catch (PostulacionException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(str_repeat('x', 30), $this->crear(['nombreSoftware' => str_repeat('x', 30)])->nombreSoftware);
    }

    public function test_la_url_debe_empezar_con_www(): void
    {
        $this->assertSame('www.empresa.cl', $this->crear(['url' => 'www.empresa.cl'])->url);

        $this->expectException(PostulacionException::class);
        $this->crear(['url' => 'https://empresa.cl']);
    }
}
