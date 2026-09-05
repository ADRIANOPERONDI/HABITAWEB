<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\LeadCreditLedgerModel;
use App\Models\PlanLaunchRampModel;
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

    private function contaComPlanoNaRampa(float $credito, string $rampStartedAt): array
    {
        $tenant = $this->contaComPlano($credito);

        model(SubscriptionModel::class)
            ->where('account_id', $tenant['account']->id)
            ->set(['ramp_started_at' => $rampStartedAt])
            ->update();

        return $tenant;
    }

    /** As três faixas de produção, com valid_from fixo no passado. */
    private function seedRampaPadrao(): void
    {
        $db = \Config\Database::connect();
        $db->table('plan_launch_ramps')->truncate();

        model(PlanLaunchRampModel::class)->insertBatch([
            ['mes_de' => 1, 'mes_ate' => 6, 'percentual' => 0, 'is_active' => true, 'valid_from' => '2020-01-01'],
            ['mes_de' => 7, 'mes_ate' => 12, 'percentual' => 50, 'is_active' => true, 'valid_from' => '2020-01-01'],
            ['mes_de' => 13, 'mes_ate' => null, 'percentual' => 100, 'is_active' => true, 'valid_from' => '2020-01-01'],
        ]);
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

    /**
     * Mensalidade R$0 na rampa (meses 1-6) é a única receita do semestre de
     * lançamento (D2) — conceder o crédito de lead JUNTO subsidiaria a
     * mesma conta duas vezes no mesmo mês.
     */
    public function testNaoConcedeCreditoNoMesGratuitoDaRampa(): void
    {
        $this->seedRampaPadrao();

        $tenant  = $this->contaComPlanoNaRampa(200.00, date('Y-m-d')); // mes 1: 0%
        $periodo = date('Y-m-01');

        (new LeadCreditService())->grantMonthly($periodo);

        $this->assertSame(0.0, (new LeadCreditService())->balanceFor((int) $tenant['account']->id, $periodo));
    }

    /** A partir do mês em que a rampa volta a cobrar, o crédito segue normal. */
    public function testConcedeQuandoARampaJaCobra(): void
    {
        $this->seedRampaPadrao();

        $tenant  = $this->contaComPlanoNaRampa(200.00, date('Y-m-d', strtotime('-7 months'))); // mes 8: 50%
        $periodo = date('Y-m-01');

        (new LeadCreditService())->grantMonthly($periodo);

        $this->assertSame(200.0, (new LeadCreditService())->balanceFor((int) $tenant['account']->id, $periodo));
    }
}
