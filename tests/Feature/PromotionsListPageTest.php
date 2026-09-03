<?php

namespace Tests\Feature;

use App\Models\PlanModel;
use App\Models\PromotionPackageModel;
use App\Models\PropertyModel;
use App\Models\SubscriptionModel;
use App\Services\PromotionService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * `GET admin/promotions` (D5) — "Minhas turbinadas". A query selecionava
 * `promotions.*` sem juntar `promotion_packages`, e a view lia
 * `$promo->pacote_key`, coluna que não existe em `promotions` — todo pacote
 * renderizava em branco. Cobre também a nova coluna "Origem".
 *
 * @internal
 */
final class PromotionsListPageTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
    }

    private function withCsrf(array $data = []): array
    {
        return array_merge([csrf_token() => csrf_hash()], $data);
    }

    public function testMostraNomeDoPacoteEOrigemDaTurbinada(): void
    {
        $planId = (int) model(PlanModel::class)->insert([
            'chave'               => 'QUOTA_LIST_' . bin2hex(random_bytes(4)),
            'nome'                => 'Plano Quota List ' . bin2hex(random_bytes(4)),
            'preco_mensal'        => 1690.00,
            'limite_turbo_mensal' => 5,
            'ativo'               => true,
        ], true);

        $tenant = (new TenantFactory())->create();
        model(SubscriptionModel::class)
            ->where('account_id', $tenant['account']->id)
            ->set(['plan_id' => $planId, 'status' => 'ACTIVE'])
            ->update();

        model(PromotionPackageModel::class)->insert([
            'chave'         => 'TURBO_LISTA_TESTE_' . bin2hex(random_bytes(3)),
            'nome'          => 'Turbinar Imóvel - 7 dias',
            'tipo_promocao' => PromotionService::TIPO_TURBO,
            'duracao_dias'  => 7,
            'preco'         => 50.00,
        ]);

        $propertyId = (int) model(PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Imovel Lista Turbinadas',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 500000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);

        $this->actingAs($tenant['user'])->post("admin/properties/{$propertyId}/turbo/cota", $this->withCsrf());

        $response = $this->actingAs($tenant['user'])->get('admin/promotions');

        $response->assertStatus(200);
        $response->assertSee('Minhas turbinadas');
        $response->assertSee('Turbinar Imóvel - 7 dias');
        $response->assertSee('Cota do plano');
    }
}
