<?php

namespace Tests\Unit;

use App\Models\PromotionPackageModel;
use App\Services\PromotionService;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre a separação entre pacote de EXPOSIÇÃO (turbinar imóvel, tem prazo) e
 * pacote de LEAD (preço por unidade, duracao_dias = 0), que dividem a mesma
 * tabela `promotion_packages`.
 *
 * listPackages() devolvia tudo, então a tela de turbinar exibia "Lead - Compra
 * R$ 80,00 por 0 dias" como se fosse comprável. Comprando, applyPackage gerava
 * cobrança e a confirmação criava promoção com data_fim = data_inicio: destaque
 * pago que já nasce expirado.
 */
final class PromotionPackagesTest extends HabitawebTestCase
{
    private function seedPackages(): void
    {
        $model = model(PromotionPackageModel::class);

        $model->insert([
            'chave'         => 'TURBO_7_DIAS',
            'nome'          => 'Turbinar Imóvel - 7 dias',
            'tipo_promocao' => 'TURBO_IMOVEL',
            'duracao_dias'  => 7,
            'preco'         => 50.00,
        ]);

        $model->insert([
            'chave'         => 'LEAD_COMPRA',
            'nome'          => 'Lead - Compra',
            'tipo_promocao' => 'LEAD',
            'duracao_dias'  => 0,
            'preco'         => 80.00,
        ]);

        $model->insert([
            'chave'         => 'LEAD_ALUGUEL',
            'nome'          => 'Lead - Aluguel',
            'tipo_promocao' => 'LEAD',
            'duracao_dias'  => 0,
            'preco'         => 40.00,
        ]);
    }

    public function testListaSoDevolvePacotesDeExposicaoComPrazo(): void
    {
        $this->seedPackages();

        $chaves = array_map(
            static fn ($p) => $p->chave,
            (new PromotionService())->listPackages()
        );

        $this->assertContains('TURBO_7_DIAS', $chaves);
        $this->assertNotContains('LEAD_COMPRA', $chaves);
        $this->assertNotContains('LEAD_ALUGUEL', $chaves);
    }

    public function testPacoteDeExposicaoSemPrazoNaoEComparavel(): void
    {
        model(PromotionPackageModel::class)->insert([
            'chave'         => 'TURBO_SEM_PRAZO',
            'nome'          => 'Turbo mal cadastrado',
            'tipo_promocao' => 'TURBO_IMOVEL',
            'duracao_dias'  => 0,
            'preco'         => 50.00,
        ]);

        $chaves = array_map(
            static fn ($p) => $p->chave,
            (new PromotionService())->listPackages()
        );

        $this->assertNotContains('TURBO_SEM_PRAZO', $chaves);
    }

    public function testAplicarPacoteDeLeadEhRejeitadoAntesDoGateway(): void
    {
        $this->seedPackages();

        // propertyId inexistente de propósito: se a validação de tipo não vier
        // primeiro, o teste falharia por outra mensagem — e nunca deve chegar
        // ao gateway, que nem está configurado no ambiente de teste.
        $result = (new PromotionService())->applyPackage(999999, 'LEAD_COMPRA');

        $this->assertFalse($result['success']);
        $this->assertSame('Este pacote não pode ser aplicado a um imóvel.', $result['message']);
    }
}
