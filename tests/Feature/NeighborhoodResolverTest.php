<?php

namespace Tests\Feature;

use App\Services\NeighborhoodResolver;
use Tests\Support\HabitawebTestCase;

/**
 * @internal
 */
final class NeighborhoodResolverTest extends HabitawebTestCase
{
    private NeighborhoodResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new NeighborhoodResolver();
    }

    public function testVariantesDeCaixaCaemNoMesmoBairro(): void
    {
        $this->assertSame('Centro', $this->resolver->resolve('São Miguel do Oeste', 'CENTRO'));
        $this->assertSame('Centro', $this->resolver->resolve('SÃO MIGUEL DO OESTE', 'Centro'));
        $this->assertSame('Centro', $this->resolver->resolve('São Miguel do Oeste', ' centro '));
        $this->assertSame('Jardim Peperi', $this->resolver->resolve('São Miguel do Oeste', 'JARDIM PEPERI'));
    }

    public function testBairroRegistradoComAcentoVenceAVarianteSemAcento(): void
    {
        $this->resolver->register(NeighborhoodResolver::cityKey('Cidade X'), 'São Jorge');

        $this->assertSame('São Jorge', $this->resolver->resolve('Cidade X', 'SAO JORGE'));
    }

    public function testMesmoNomeEmCidadesDiferentesNaoSeMistura(): void
    {
        $this->resolver->addAlias('Cidade A', 'Centro Velho', 'Centro');

        $this->assertSame('Centro', $this->resolver->resolve('Cidade A', 'centro velho'));
        $this->assertSame('Centro Velho', $this->resolver->resolve('Cidade B', 'CENTRO VELHO'));
    }

    public function testNomesDistintosNaoSaoFundidosSemAlias(): void
    {
        $this->assertSame('Centro', $this->resolver->resolve('Cidade C', 'Centro'));
        $this->assertSame('Centro Histórico', $this->resolver->resolve('Cidade C', 'CENTRO HISTÓRICO'));
    }

    public function testAliasAssociaNomeDiferenteAoBairroCorreto(): void
    {
        $this->resolver->addAlias('Cidade D', 'Centro Histórico', 'Centro');

        $this->assertSame('Centro', $this->resolver->resolve('Cidade D', 'CENTRO HISTÓRICO'));
    }

    public function testVazioENulo(): void
    {
        $this->assertNull($this->resolver->resolve('Cidade D', null));
        $this->assertSame('', $this->resolver->resolve('Cidade D', '  '));
    }
}
