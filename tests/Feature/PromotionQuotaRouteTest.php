<?php

namespace Tests\Feature;

use App\Models\PlanModel;
use App\Models\PromotionModel;
use App\Models\PromotionPackageModel;
use App\Models\PropertyModel;
use App\Models\SubscriptionModel;
use App\Services\PromotionService;
use App\Services\TurboService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Turbinada da cota mensal do plano (C8) e restrição de `is_destaque` a
 * superadmin (P5) — desde a Fase 1, o tenant só tem a turbinada (paga ou da
 * cota); o selo editorial não é mais algo que ele compra ou controla.
 *
 * @internal
 */
final class PromotionQuotaRouteTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // PlanGate::for() memoiza por accountId em cache — sem limpar, um
        // teste anterior que já resolveu esta conta (impossível aqui, mas
        // segue o mesmo cuidado de LaunchRampIntegrationTest) vazaria plano.
        cache()->clean();
    }

    private function withCsrf(array $data = []): array
    {
        return array_merge([csrf_token() => csrf_hash()], $data);
    }

    private function contaComCotaDeTurbo(int $limiteTurboMensal): array
    {
        $planId = (int) model(PlanModel::class)->insert([
            'chave'               => 'QUOTA_' . bin2hex(random_bytes(4)),
            'nome'                => 'Plano Quota ' . bin2hex(random_bytes(4)),
            'preco_mensal'        => 1690.00,
            'limite_turbo_mensal' => $limiteTurboMensal,
            'ativo'               => true,
        ], true);

        $tenant = (new TenantFactory())->create();

        model(SubscriptionModel::class)
            ->where('account_id', $tenant['account']->id)
            ->set(['plan_id' => $planId, 'status' => 'ACTIVE'])
            ->update();

        return $tenant;
    }

    private function imovel(array $tenant): int
    {
        return (int) model(PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Imovel Quota',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 500000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);
    }

    private function pacoteTurbo(): void
    {
        model(PromotionPackageModel::class)->insert([
            'chave'         => 'TURBO_QUOTA_TESTE_' . bin2hex(random_bytes(3)),
            'nome'          => 'Turbo Quota Teste',
            'tipo_promocao' => PromotionService::TIPO_TURBO,
            'duracao_dias'  => 7,
            'preco'         => 50.00,
        ]);
    }

    /**
     * A rota nova é o único caminho pra turbinar sem passar pelo gateway —
     * `TurboService::activateFromQuota()` já é testado isoladamente em
     * outro lugar; aqui o que importa é que a ROTA/controller resolvem o
     * imóvel certo e devolvem o resultado pro tenant.
     */
    public function testUsaTurbinadaDaCotaPelaRota(): void
    {
        $this->pacoteTurbo();
        $tenant     = $this->contaComCotaDeTurbo(5);
        $propertyId = $this->imovel($tenant);

        $response = $this->actingAs($tenant['user'])->post(
            "admin/properties/{$propertyId}/turbo/cota",
            $this->withCsrf()
        );

        $response->assertRedirect();

        $imovel = model(PropertyModel::class)->find($propertyId);
        $this->assertGreaterThan(0, (int) $imovel->highlight_level, 'a turbinada da cota precisa ativar o destaque');

        $this->assertSame(
            1,
            model(PromotionModel::class)
                ->where('property_id', $propertyId)
                ->where('origem', TurboService::ORIGEM_PLANO)
                ->countAllResults(),
            'precisa gerar uma promotions com origem PLANO, nao PAGO'
        );
    }

    /**
     * `toggle-destaque` ganhou `filter => group:superadmin` (C8/P5) — um
     * tenant comum não pode mais alcançar essa rota, mesmo sabendo a URL.
     */
    public function testTenantNaoAlcancaToggleDestaque(): void
    {
        $tenant     = (new TenantFactory())->create();
        $propertyId = $this->imovel($tenant);

        $this->actingAs($tenant['user'])->post(
            "admin/properties/{$propertyId}/toggle-destaque",
            $this->withCsrf()
        )->assertRedirect();

        $imovel = model(PropertyModel::class)->find($propertyId);
        $this->assertFalse((bool) $imovel->is_destaque, 'tenant nao pode alcancar o toggle de destaque');
    }
}
