<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Rótulo do marcador de preço dos mapas (home e busca): imóvel sem preço não
 * pode virar "R$ 0" e o arredondamento não pode mudar a percepção do valor
 * (2.500/mês não é "3 mil").
 *
 * @internal
 */
final class MapPriceLabelTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('format');
    }

    /**
     * @dataProvider valoresProvider
     */
    public function testFormataSemDistorcerOValor(float $valor, string $esperado): void
    {
        $this->assertSame($esperado, map_price_label($valor));
    }

    public static function valoresProvider(): iterable
    {
        yield 'aluguel 1250'      => [1250.0, 'R$ 1.250'];
        yield 'aluguel 2500'      => [2500.0, 'R$ 2.500'];
        yield 'aluguel 2850'      => [2850.0, 'R$ 2.850'];
        yield 'limite 9999'       => [9999.0, 'R$ 9.999'];
        yield '10 mil'            => [10000.0, 'R$ 10 mil'];
        yield '12,5 mil'          => [12500.0, 'R$ 12,5 mil'];
        yield '350 mil'           => [350000.0, 'R$ 350 mil'];
        yield '999,5 mil'         => [999500.0, 'R$ 999,5 mil'];
        yield 'quase 1 mi'        => [999960.0, 'R$ 1 mi'];
        yield '1 mi'              => [1000000.0, 'R$ 1 mi'];
        yield '1,25 mi'           => [1250000.0, 'R$ 1,25 mi'];
        yield '3,5 mi'            => [3500000.0, 'R$ 3,5 mi'];
        yield '12,34 mi'          => [12340000.0, 'R$ 12,34 mi'];
    }

    public function testSemPrecoNaoViraGratis(): void
    {
        $this->assertSame('Sob consulta', map_price_label(0.0));
        $this->assertSame('Sob consulta', map_price_label(null));
        $this->assertSame('Sob consulta', map_price_label(-5.0));
    }
}
