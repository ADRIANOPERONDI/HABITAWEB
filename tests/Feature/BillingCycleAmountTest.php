<?php

namespace Tests\Feature;

use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use App\Services\PaymentService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre o valor cobrado por ciclo de assinatura.
 *
 * `getPlanAmountForBillingCycle` usava `$plan->preco_anual ?? ($monthly * 12)`.
 * O `??` nunca disparava: as colunas de preço por ciclo têm DEFAULT 0.00, e
 * `0.00 ?? x` devolve 0.00. Como o PlanSeeder preenche só preco_mensal e
 * preco_anual, os três planos de produção têm trimestral e semestral zerados —
 * e o checkout validava `billing_cycle` apenas por in_list, sem conferir se o
 * plano vendia aquele ciclo. Um POST direto com QUARTERLY assinava 3 meses por
 * R$ 0,00.
 */
final class BillingCycleAmountTest extends HabitawebTestCase
{
    private function makePlan(array $overrides = []): object
    {
        $model = model(PlanModel::class);
        $model->insert(array_merge([
            'chave'            => 'CICLO_' . bin2hex(random_bytes(4)),
            'nome'             => 'Plano Ciclo ' . bin2hex(random_bytes(4)),
            'preco_mensal'     => 990.00,
            'preco_trimestral' => 0.00,
            'preco_semestral'  => 0.00,
            'preco_anual'      => 0.00,
            'ativo'            => true,
        ], $overrides));

        return $model->find($model->getInsertID());
    }

    /** getPlanAmountForBillingCycle é protected; exercitado por reflexão. */
    private function amountFor(object $plan, string $cycle): float
    {
        $service = new PaymentService();
        $method  = (new \ReflectionClass($service))->getMethod('getPlanAmountForBillingCycle');

        return (float) $method->invoke($service, $plan, $cycle);
    }

    public function testCicloSemPrecoCadastradoNaoSaiDeGraca(): void
    {
        $plan = $this->makePlan(['preco_mensal' => 990.00]);

        $this->assertSame(990.0, $this->amountFor($plan, 'MONTHLY'));
        $this->assertSame(2970.0, $this->amountFor($plan, 'QUARTERLY'));
        $this->assertSame(5940.0, $this->amountFor($plan, 'SEMIANNUALLY'));
        $this->assertSame(11880.0, $this->amountFor($plan, 'YEARLY'));
    }

    public function testPrecoProprioDoCicloTemPrecedencia(): void
    {
        $plan = $this->makePlan([
            'preco_mensal' => 990.00,
            'preco_anual'  => 9900.00,
        ]);

        $this->assertSame(9900.0, $this->amountFor($plan, 'YEARLY'));
    }

    public function testPlanoSuportaApenasOsCiclosComPrecoCadastrado(): void
    {
        $service = new PaymentService();
        $plan    = $this->makePlan(['preco_mensal' => 990.00, 'preco_anual' => 9900.00]);

        $this->assertTrue($service->planSupportsBillingCycle($plan, 'MONTHLY'));
        $this->assertTrue($service->planSupportsBillingCycle($plan, 'YEARLY'));
        $this->assertFalse($service->planSupportsBillingCycle($plan, 'QUARTERLY'));
        $this->assertFalse($service->planSupportsBillingCycle($plan, 'SEMIANNUALLY'));
    }

    /**
     * O checkout é rota web e passa pelo filtro csrf global. O FeatureTestTrait
     * não monta o token, então o POST morreria em SecurityException antes de
     * chegar ao controller. Aqui só o csrf sai dos globals — o resto da pilha de
     * filtros continua valendo, que é o ponto do teste.
     */
    private function withoutCsrf(): void
    {
        $filters = config('Filters');
        unset($filters->globals['before']['csrf']);
        \CodeIgniter\Config\Factories::injectMock('config', 'Filters', $filters);
    }

    public function testCheckoutRejeitaCicloQueOPlanoNaoVende(): void
    {
        $this->withoutCsrf();

        $tenant = (new TenantFactory())->create();
        $plan   = $this->makePlan(['preco_mensal' => 990.00, 'preco_trimestral' => 0.00]);

        $response = $this->actingAs($tenant['user'])->post('checkout/process', [
            'plan_id'       => $plan->id,
            'billing_type'  => 'PIX',
            'billing_cycle' => 'QUARTERLY',
        ]);

        $response->assertSessionHas('error', 'Este plano não está disponível na periodicidade escolhida.');

        $created = model(SubscriptionModel::class)
            ->where('account_id', $tenant['account']->id)
            ->where('plan_id', $plan->id)
            ->countAllResults();

        $this->assertSame(0, $created, 'Nenhuma assinatura pode nascer de um ciclo sem preço.');
    }
}
