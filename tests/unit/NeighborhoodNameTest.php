<?php

namespace Tests\Unit;

use App\Libraries\Text\NeighborhoodName;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class NeighborhoodNameTest extends CIUnitTestCase
{
    /**
     * @dataProvider variantesProvider
     */
    public function testVariantesDeCaixaEEspacoViramUmaSoForma(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, NeighborhoodName::normalize($entrada));
    }

    public static function variantesProvider(): iterable
    {
        yield ['CENTRO', 'Centro'];
        yield ['centro', 'Centro'];
        yield ['  Centro  ', 'Centro'];
        yield ['AGOSTINI', 'Agostini'];
        yield ['SALETE', 'Salete'];
        yield ['JARDIM PEPERI', 'Jardim Peperi'];
        yield ['jardim   peperi', 'Jardim Peperi'];
        yield ['VILA DOS PINHEIROS', 'Vila dos Pinheiros'];
        yield ['LOTEAMENTO SAO PAULO II', 'Loteamento Sao Paulo II'];
        yield 'caixa mista fica como esta' => ['VivaPark', 'VivaPark'];
        yield 'parenteses mistos' => ['Planejado (Belvedere)', 'Planejado (Belvedere)'];
        yield 'parenteses em maiusculas' => ['PLANEJADO (BELVEDERE)', 'Planejado (Belvedere)'];
        yield 'mista com espaco extra' => ['  VivaPark  ', 'VivaPark'];
    }

    public function testChaveIgnoraCaixaAcentoEEspacoMasNaoJuntaNomesDistintos(): void
    {
        $this->assertSame(NeighborhoodName::key('SÃO  JORGE'), NeighborhoodName::key('Sao Jorge'));
        $this->assertNotSame(NeighborhoodName::key('Centro'), NeighborhoodName::key('Centro Histórico'));
        $this->assertNotSame(NeighborhoodName::key('Jardim Peperi'), NeighborhoodName::key('Peperi'));
    }

    public function testDetectaPontoDeReferenciaNoLugarDeBairro(): void
    {
        $this->assertTrue(NeighborhoodName::looksLikeReference('Próximo a Agropecuária Terra Viva'));
        $this->assertTrue(NeighborhoodName::looksLikeReference('PERTO DE MERCADO'));
        $this->assertFalse(NeighborhoodName::looksLikeReference('Centro'));
        $this->assertFalse(NeighborhoodName::looksLikeReference('Jardim Peperi'));
        $this->assertFalse(NeighborhoodName::looksLikeReference(null));
    }

    public function testNulosEVazios(): void
    {
        $this->assertNull(NeighborhoodName::normalize(null));
        $this->assertSame('', NeighborhoodName::normalize('   '));
    }
}
