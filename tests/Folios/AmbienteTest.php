<?php

namespace Winex\Certificacion\Tests\Folios;

use PHPUnit\Framework\TestCase;
use Winex\Certificacion\Folios\Ambiente;

final class AmbienteTest extends TestCase
{
    public function test_certificacion_apunta_a_maullin(): void
    {
        $this->assertSame('maullin.sii.cl', Ambiente::Certificacion->host());
        $this->assertSame('https://maullin.sii.cl', Ambiente::Certificacion->portal());
        $this->assertSame('100', Ambiente::Certificacion->idk());
    }

    public function test_produccion_apunta_a_palena(): void
    {
        $this->assertSame('palena.sii.cl', Ambiente::Produccion->host());
        $this->assertSame('https://palena.sii.cl', Ambiente::Produccion->portal());
        $this->assertSame('300', Ambiente::Produccion->idk());
    }

    public function test_los_ambientes_nunca_comparten_host_ni_idk(): void
    {
        $this->assertNotSame(Ambiente::Certificacion->host(), Ambiente::Produccion->host());
        $this->assertNotSame(Ambiente::Certificacion->idk(), Ambiente::Produccion->idk());
    }
}
