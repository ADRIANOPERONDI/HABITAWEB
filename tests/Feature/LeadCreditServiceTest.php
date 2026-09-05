<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\LeadCreditLedgerModel;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use App\Services\LeadCreditService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

final class LeadCreditServiceTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        cache()->clean();
    }

    private function planComCredito(float $credito): int
    {
        $model = model(PlanModel::class);
        $model->insert([
            'chave'                 => 'CREDITO_' . bin2hex(random_bytes(4)),
            'nome'                  => 'Plano Credito ' . bin2hex(random_bytes(4)),
            'preco_mensal'          => 1690.00,
            'credito_leads_mensal'  => $credito,
            'ativo'                 => true,
        ]);

        return (int) $model->getInsertID();
    }

    private function contaComPlano(float $credito): array
    {
        $tenant = (new TenantFactory())->create();
        $planId = $this->planComCredito($credito);

        model(SubscriptionModel::class)
            ->where('account_id', $tenant['account']->id)
            ->set(['plan_id' => $planId, 'status' => 'ACTIVE'])
            ->update();

        return $tenant;
    }

    public function testConcedeCreditoParaContaComPlanoElegivel(): void
    {
        $tenant  = $this->contaComPlano(200.00);
        $periodo = date('Y-m-01');

        $n = (new LeadCreditService())->grantMonthly($periodo);

        $this->assertGreaterThanOrEqual(1, $n);
        $this->assertSame(200.0, (new LeadCreditService())->balanceFor((int) $tenant['account']->id, $periodo));
    }

    public function testRodarDuasVezesNoMesmoMesNaoDuplicaAConcessao(): void
    {
        $tenant  = $this->contaComPlano(200.00);
        $periodo = date('Y-m-01');

        $service = new LeadCreditService();
        $service->grantMonthly($periodo);
        $service->grantMonthly($periodo);

        $this->assertSame(200.0, $service->balanceFor((int) $tenant['account']->id, $periodo));
        $this->assertSame(
            1,
            model(LeadCreditLedgerModel::class)
                ->where('account_id', $tenant['account']->id)
                ->where('periodo', $periodo)
                ->countAllResults()
        );
    }

    public function testPlanoSemCreditoNaoRecebeNada(): void
    {
        $tenant  = $this->contaComPlano(0.00);
        $periodo = date('Y-m-01');

        (new LeadCreditService())->grantMonthly($periodo);

        $this->assertSame(0.0, (new LeadCreditService())->balanceFor((int) $tenant['account']->id, $periodo));
    }

    public function testConsumeAbateDoSaldoSemPassarDoDisponivel(): void
    {
        $tenant  = $this->contaComPlano(200.00);
        $periodo = date('Y-m-01');
        $service = new LeadCreditService();
        $service->grantMonthly($periodo);

        $consumido = $service->consume((int) $tenant['account']->id, $periodo, 500.00);

        $this->assertSame(200.0, $consumido, 'nao pode consumir mais do que existe de saldo');
        $this->assertSame(0.0, $service->balanceFor((int) $tenant['account']->id, $periodo));
    }

    public function testConsumeParcialDeixaOResto(): void
    {
        $tenant  = $this->contaComPlano(200.00);
        $periodo = date('Y-m-01');
        $service = new LeadCreditService();
        $service->grantMonthly($periodo);

        $consumido = $service->consume((int) $tenant['account']->id, $periodo, 80.00);

        $this->assertSame(80.0, $consumido);
        $this->assertSame(120.0, $service->balanceFor((int) $tenant['account']->id, $periodo));
    }

    public function testExpireRemainingZeraOSaldoEDeixaRastro(): void
    {
        $tenant  = $this->contaComPlano(200.00);
        $periodo = date('Y-m-01');
        $service = new LeadCreditService();
        $service->grantMonthly($periodo);
        $service->consume((int) $tenant['account']->id, $periodo, 50.00);

        $expirado = $service->expireRemaining((int) $tenant['account']->id, $periodo);

        $this->assertSame(150.0, $expirado);
        $this->assertSame(0.0, $service->balanceFor((int) $tenant['account']->id, $periodo));
    }

    /** O credito de um periodo nao paga fatura de outro periodo. */
    public function testSaldoNaoVazaEntrePeriodos(): void
    {
        $tenant        = $this->contaComPlano(200.00);
        $periodoPassado = date('Y-m-01', strtotime('-1 month'));
        $periodoAtual   = date('Y-m-01');

        (new LeadCreditService())->grantMonthly($periodoPassado);

        $this->assertSame(0.0, (new LeadCreditService())->balanceFor((int) $tenant['account']->id, $periodoAtual));
    }
}
