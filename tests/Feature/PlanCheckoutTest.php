<?php

namespace Tests\Feature;

use App\Models\PlanModel;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre a vitrine pública de planos (GET checkout/plans).
 *
 * A view lia `$plan->limite_imoveis`, `limite_fotos`, `limite_destaques` e `preco`
 * — quatro campos que não existem em `plans`. Como Entity devolve null para
 * propriedade desconhecida em vez de estourar, a página renderizava com os limites
 * em branco: o cliente via "Imóveis", "Fotos/Imóvel" e "Destaques" sem número
 * nenhum ao lado. Estes testes prendem os nomes reais das colunas.
 */
final class PlanCheckoutTest extends HabitawebTestCase
{
    private function makePlan(array $overrides = []): int
    {
        $model = model(PlanModel::class);

        $model->insert(array_merge([
            'chave'                   => 'TESTE_' . bin2hex(random_bytes(4)),
            'nome'                    => 'Plano Teste ' . bin2hex(random_bytes(4)),
            'limite_imoveis_ativos'   => 45,
            'limite_turbo_mensal'     => 10,
            'limite_fotos_por_imovel' => 12,
            'preco_mensal'            => 1850.00,
            'ativo'                   => true,
        ], $overrides));

        return (int) $model->getInsertID();
    }

    public function testListaExibeOsLimitesReaisDoPlano(): void
    {
        $this->makePlan([
            'nome'                    => 'Plano Vitrine',
            'limite_imoveis_ativos'   => 45,
            'limite_fotos_por_imovel' => 12,
            'limite_turbo_mensal'     => 10,
            'preco_mensal'            => 1850.00,
        ]);

        $response = $this->get('checkout/plans');

        $response->assertStatus(200);
        $response->assertSee('Plano Vitrine');
        $response->assertSee('1.850,00');
        $response->assertSee('45 Imóveis');
        $response->assertSee('12 Fotos/Imóvel');
        $response->assertSee('10 Destaques');
    }

    public function testLimiteNuloAparaceComoIlimitado(): void
    {
        $this->makePlan([
            'nome'                    => 'Plano Sem Teto',
            'limite_imoveis_ativos'   => null,
            'limite_turbo_mensal'     => null,
            'limite_fotos_por_imovel' => null,
        ]);

        $response = $this->get('checkout/plans');

        $response->assertStatus(200);
        $response->assertSee('Imóveis ilimitados');
        $response->assertSee('Fotos ilimitadas');
        $response->assertSee('Destaques ilimitados');
    }

    public function testPlanoInativoNaoAparece(): void
    {
        $this->makePlan(['nome' => 'Plano Aposentado', 'ativo' => false]);

        $response = $this->get('checkout/plans');

        $response->assertStatus(200);
        $response->assertDontSee('Plano Aposentado');
    }
}
