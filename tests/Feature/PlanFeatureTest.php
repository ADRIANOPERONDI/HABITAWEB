<?php

namespace Tests\Feature;

use App\Entities\PlanFeature;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use App\Services\PlanGate;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre o mecanismo de features por plano e o PlanGate.
 *
 * Dois pontos merecem teste explícito porque falham em silêncio:
 *
 * 1. O cast de `features` precisa estar no MODEL. O `$casts` da entity não se
 *    aplica ao que o model lê do banco, e sem ele `features` chega como string
 *    JSON — `$features['painel.completo']` num string devolve o primeiro
 *    caractere, que é truthy. Todo plano pareceria ter todo recurso.
 *
 * 2. `ativo` também: o Postgres devolve boolean como 't'/'f', e a string 'f' é
 *    truthy no PHP. Um plano desativado passaria por ativo em qualquer
 *    `if ($plan->ativo)`.
 */
final class PlanFeatureTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlanGate::flushMemo();
        cache()->clean();
    }

    private function makePlan(array $overrides = []): int
    {
        $model = model(PlanModel::class);
        $model->insert(array_merge([
            'chave'        => 'FEAT_' . bin2hex(random_bytes(4)),
            'nome'         => 'Plano Feature ' . bin2hex(random_bytes(4)),
            'preco_mensal' => 990.00,
            'ativo'        => true,
        ], $overrides));

        return (int) $model->getInsertID();
    }

    public function testFeaturesVoltamDoBancoComoArray(): void
    {
        $id = $this->makePlan([
            'features' => [PlanFeature::PAINEL_COMPLETO => true],
        ]);

        $plan = model(PlanModel::class)->find($id);

        $this->assertIsArray(
            $plan->features,
            'Sem o cast json-array no MODEL, features chega como string e toda flag vira truthy.'
        );
        $this->assertTrue($plan->has(PlanFeature::PAINEL_COMPLETO));
    }

    public function testFeatureAusenteEhFalse(): void
    {
        $plan = model(PlanModel::class)->find($this->makePlan());

        $this->assertFalse($plan->has(PlanFeature::PAINEL_COMPLETO));
        $this->assertFalse($plan->has(PlanFeature::PAGINA_PREMIUM));
        $this->assertSame([], $plan->activeFeatures());
    }

    public function testFeatureDesligadaExplicitamenteEhFalse(): void
    {
        $plan = model(PlanModel::class)->find($this->makePlan([
            'features' => [
                PlanFeature::PAINEL_COMPLETO => false,
                PlanFeature::PAGINA_PREMIUM  => true,
            ],
        ]));

        $this->assertFalse($plan->has(PlanFeature::PAINEL_COMPLETO));
        $this->assertSame([PlanFeature::PAGINA_PREMIUM], $plan->activeFeatures());
    }

    public function testPlanoInativoNaoEhTruthy(): void
    {
        $plan = model(PlanModel::class)->find($this->makePlan(['ativo' => false]));

        $this->assertFalse(
            (bool) $plan->ativo,
            "Postgres devolve 'f' e a string 'f' é truthy no PHP — o cast boolean no model é o que impede isso."
        );
    }

    public function testLimitesNulosSignificamIlimitado(): void
    {
        $plan = model(PlanModel::class)->find($this->makePlan([
            'limite_imoveis_ativos' => null,
            'limite_turbo_mensal'   => null,
        ]));

        $this->assertTrue($plan->isIlimitadoImoveis());
        $this->assertNull($plan->turbosIncluidos());
    }

    public function testGateRespondePelaAssinaturaVigente(): void
    {
        $tenant    = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;

        $planId = $this->makePlan([
            'features' => [PlanFeature::PAINEL_COMPLETO => true],
        ]);

        model(SubscriptionModel::class)
            ->where('account_id', $accountId)
            ->set(['plan_id' => $planId, 'status' => 'ACTIVE'])
            ->update();

        PlanGate::forget($accountId);

        $this->assertTrue(PlanGate::has($accountId, PlanFeature::PAINEL_COMPLETO));
        $this->assertFalse(PlanGate::has($accountId, PlanFeature::PAGINA_PREMIUM));
    }

    public function testContaSemAssinaturaVigenteNaoTemFeature(): void
    {
        $tenant    = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;

        $planId = $this->makePlan([
            'features' => [PlanFeature::PAINEL_COMPLETO => true],
        ]);

        model(SubscriptionModel::class)
            ->where('account_id', $accountId)
            ->set(['plan_id' => $planId, 'status' => 'CANCELLED'])
            ->update();

        PlanGate::forget($accountId);

        $this->assertNull(PlanGate::for($accountId));
        $this->assertFalse(
            PlanGate::has($accountId, PlanFeature::PAINEL_COMPLETO),
            'Assinatura cancelada não pode continuar concedendo recurso.'
        );
    }

    public function testTrocaDePlanoRefleteSemEsperarOCache(): void
    {
        $tenant    = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;

        $basico = $this->makePlan(['features' => []]);
        $premium = $this->makePlan(['features' => [PlanFeature::PAGINA_PREMIUM => true]]);

        $subModel = model(SubscriptionModel::class);
        $subModel->where('account_id', $accountId)->set(['plan_id' => $basico, 'status' => 'ACTIVE'])->update();
        PlanGate::forget($accountId);

        $this->assertFalse(PlanGate::has($accountId, PlanFeature::PAGINA_PREMIUM));

        // Upgrade pelo model: o callback afterUpdate deve derrubar o cache.
        $sub = $subModel->where('account_id', $accountId)->first();
        $subModel->update($sub->id, ['plan_id' => $premium]);
        PlanGate::flushMemo();

        $this->assertTrue(
            PlanGate::has($accountId, PlanFeature::PAGINA_PREMIUM),
            'O tenant não pode pagar o upgrade e esperar o TTL do cache para receber o recurso.'
        );
    }
}
