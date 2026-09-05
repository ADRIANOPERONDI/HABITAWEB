<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * `price_label()` (D2) — preço público de imóvel. Preço zerado ou ausente
 * (anunciante sem valor cadastrado, ou Simob mandando o campo em branco) não
 * pode virar "R$ 0,00" na vitrine, que passa a ideia de imóvel de graça.
 *
 * @internal
 */
final class PublicPriceLabelTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('format');
    }

    public function testPrecoZeroViraSobConsulta(): void
    {
        $this->assertSame('Sob consulta', price_label(0.0, 'VENDA'));
        $this->assertSame('Sob consulta', price_label(null, 'VENDA'));
        $this->assertSame('Sob consulta', price_label(-10.0, 'ALUGUEL'));
    }

    public function testAluguelMostraPorMes(): void
    {
        $this->assertSame('R$ 1.500,00/mês', price_label(1500.0, 'ALUGUEL'));
    }

    public function testVendaNaoMostraPorMes(): void
    {
        $this->assertSame('R$ 450.000,00', price_label(450000.0, 'VENDA'));
    }
}
