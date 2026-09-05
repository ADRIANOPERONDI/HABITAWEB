<?php

namespace Tests\Feature;

use App\Models\AuditLogModel;
use App\Models\PlanLaunchRampModel;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\Fakes\FakePaymentGateway;
use Tests\Support\HabitawebTestCase;

/**
 * `spark assinaturas:aplicar-rampa` — o cron que aplica as transições de
 * faixa. A garantia mais delicada aqui: a primeira cobrança real de uma
 * conta (0%→X%, sem assinatura ainda no gateway) NUNCA é automatizada —
 * fica marcada para ação manual, porque o comando não tem como avisar o
 * cliente com antecedência nem escolher forma de pagamento por ele. Já a
 * correção de valor numa assinatura que já existe no gateway (qualquer
 * outra transição) é mecânica e roda sozinha.
 */
final class ApplyLaunchRampCommandTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakePaymentGateway::$subscriptionUpdates = [];

        $db = \Config\Database::connect();
        $db->table('plan_launch_ramps')->truncate();
        model(PlanLaunchRampModel::class)->insertBatch([
            ['mes_de' => 1, 'mes_ate' => 6, 'percentual' => 0, 'is_active' => true, 'valid_from' => '2020-01-01'],
            ['mes_de' => 7, 'mes_ate' => 12, 'percentual' => 50, 'is_active' => true, 'valid_from' => '2020-01-01'],
            ['mes_de' => 13, 'mes_ate' => null, 'percentual' => 100, 'is_active' => true, 'valid_from' => '2020-01-01'],
        ]);
    }

    private function ativarGatewayFake(): void
    {
        $db = \Config\Database::connect();
        $db->query('UPDATE payment_gateways SET is_primary = false');
        $db->table('payment_gateways')->insert([
            'code'       => 'fake_' . bin2hex(random_bytes(4)),
            'name'       => 'Fake',
            'class_name' => FakePaymentGateway::class,
            'is_active'  => true,
            'is_primary' => true,
        ]);
    }

    private function contaNaRampa(int $mesesAtras, ?int $percentGravado, array $overrides = []): array
    {
        $tenant = (new TenantFactory())->create();
        $subscriptionModel = model(SubscriptionModel::class);
        $subscriptionModel->update($tenant['subscription']->id, array_merge([
            'ramp_started_at'    => date('Y-m-d', strtotime("-{$mesesAtras} months")),
            'ramp_percent_atual' => $percentGravado,
        ], $overrides));

        return $tenant;
    }

    private function runCommand(bool $dryRun = false): void
    {
        ob_start();
        command('assinaturas:aplicar-rampa' . ($dryRun ? ' --dry-run' : ''));
        ob_end_clean();
    }

    public function testPrimeiroContatoEstabeleceBaselineSemAcao(): void
    {
        $tenant = $this->contaNaRampa(0, null); // mes 1, nunca avaliado

        $this->runCommand();

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertSame(0, (int) $sub->ramp_percent_atual);
        $this->assertSame([], FakePaymentGateway::$subscriptionUpdates);
        $this->assertSame(0, model(AuditLogModel::class)->where('account_id', $tenant['account']->id)->countAllResults());
    }

    public function testSemMudancaDePercentualNaoFazNada(): void
    {
        $tenant = $this->contaNaRampa(2, 0); // mes 3, ainda 0%, ja gravado

        $this->runCommand();

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertSame(0, (int) $sub->ramp_percent_atual);
        $this->assertSame(0, model(AuditLogModel::class)->where('account_id', $tenant['account']->id)->countAllResults());
    }

    public function testTransicaoDeZeroParaCinquentaSemAssinaturaNoGatewayFicaParaAcaoManual(): void
    {
        $tenant = $this->contaNaRampa(7, 0); // mes 8: 50%, gravado ainda 0%, sem asaas_subscription_id

        $this->runCommand();

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        // Não atualiza SOZINHO no gateway -- fica marcado como pendente para
        // o operador (ver AccountSubscriptionController::startGateway). Mas
        // ramp_percent_atual É gravado mesmo assim: sem isso, a próxima
        // execução encontraria o mesmo "gravado !== atual" e reabriria a
        // mesma transição (auditando de novo) todo dia até alguém completar
        // a virada manualmente.
        $this->assertSame(50, (int) $sub->ramp_percent_atual);
        $this->assertSame([], FakePaymentGateway::$subscriptionUpdates, 'nao deveria falar com o gateway sem metodo de pagamento confirmado');

        $this->assertSame(
            1,
            model(AuditLogModel::class)
                ->where('account_id', $tenant['account']->id)
                ->where('action', 'ramp.pronta_para_cobranca_inicial')
                ->countAllResults()
        );

        // Rodar de novo no mesmo dia não pode reabrir a mesma transição —
        // é exatamente o que gravar ramp_percent_atual acima evita.
        $this->runCommand();
        $this->assertSame(
            1,
            model(AuditLogModel::class)
                ->where('account_id', $tenant['account']->id)
                ->where('action', 'ramp.pronta_para_cobranca_inicial')
                ->countAllResults(),
            'gravar ramp_percent_atual no caso manual evita re-auditar a mesma transicao todo dia'
        );
    }

    public function testTransicaoComAssinaturaJaExistenteNoGatewayAtualizaSozinha(): void
    {
        $this->ativarGatewayFake();

        $ouroId = (int) model(PlanModel::class)->insert([
            'chave' => 'OURO_' . bin2hex(random_bytes(3)),
            'nome' => 'Ouro ' . bin2hex(random_bytes(3)),
            'preco_mensal' => 1690.00,
            'ativo' => true,
        ], true);

        $tenant = $this->contaNaRampa(12, 50, [ // mes 13: 100%, gravado 50%
            'plan_id' => $ouroId,
            'asaas_subscription_id' => 'fake_sub_' . bin2hex(random_bytes(3)),
            'asaas_customer_id' => 'fake_cus_' . bin2hex(random_bytes(3)),
        ]);

        $this->runCommand();

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertSame(100, (int) $sub->ramp_percent_atual);
        $this->assertEquals(1690.0, (float) $sub->valor);

        $this->assertCount(1, FakePaymentGateway::$subscriptionUpdates);
        $this->assertEquals(1690.0, FakePaymentGateway::$subscriptionUpdates[0]['data']['amount'], '', 0.01);

        $log = model(AuditLogModel::class)->where('account_id', $tenant['account']->id)->first();
        $this->assertSame('ramp.valor_atualizado', $log->action);
    }

    public function testDryRunNaoGravaNadaMesmoComTransicaoDetectavel(): void
    {
        $this->ativarGatewayFake();
        $tenant = $this->contaNaRampa(7, 0, [
            'asaas_subscription_id' => 'fake_sub_x',
            'asaas_customer_id' => 'fake_cus_x',
        ]);

        $this->runCommand(dryRun: true);

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertSame(0, (int) $sub->ramp_percent_atual, 'dry-run nao grava nada');
        $this->assertSame([], FakePaymentGateway::$subscriptionUpdates);
        $this->assertSame(0, model(AuditLogModel::class)->where('account_id', $tenant['account']->id)->countAllResults());
    }

    public function testAssinaturaSemRampaENuncaTocadaPeloComando(): void
    {
        $tenant = (new TenantFactory())->create(); // sem ramp_started_at

        $this->runCommand();

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertNull($sub->ramp_percent_atual);
    }
}
