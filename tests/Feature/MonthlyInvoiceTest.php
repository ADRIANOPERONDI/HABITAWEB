<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\LeadChargeModel;
use App\Models\LeadChargeRuleModel;
use App\Models\PaymentTransactionModel;
use App\Models\PlanModel;
use App\Models\PropertyModel;
use App\Models\SubscriptionModel;
use App\Services\MonthlyInvoiceService;
use App\Services\WebhookService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\Fakes\FailingPaymentGateway;
use Tests\Support\Fakes\FakePaymentGateway;
use Tests\Support\HabitawebTestCase;

/**
 * Fatura única mensal (decisão do cliente): mensalidade do mês + leads do mês
 * anterior numa só cobrança, PIX ou boleto, vencendo dia 10 (próximo dia útil).
 *
 * @internal
 */
final class MonthlyInvoiceTest extends HabitawebTestCase
{
    private const COMPETENCIA = '2026-11-01';
    private const OUTUBRO     = '2026-10-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        cache()->clean();
        FakePaymentGateway::$paymentsCreated = [];
    }

    private function ativarGateway(string $classe = FakePaymentGateway::class): void
    {
        $db = \Config\Database::connect();
        $db->query('UPDATE payment_gateways SET is_primary = false');
        $db->table('payment_gateways')->insert([
            'code' => 'fake_' . bin2hex(random_bytes(4)), 'name' => 'Fake', 'class_name' => $classe,
            'is_active' => true, 'is_primary' => true,
        ]);
    }

    /** Conta PRATA (R$ 990) cobrada pela plataforma, com N leads aprovados de outubro a R$ 80. */
    private function conta(int $leads = 3, array $assinatura = []): array
    {
        $tenant = (new TenantFactory())->create([], 'PRATA');
        $accountId = (int) $tenant['account']->id;
        model(SubscriptionModel::class)->update($tenant['subscription']->id, array_merge(['data_fim' => null], $assinatura));

        $ruleId = (int) model(LeadChargeRuleModel::class)->insert([
            'account_id' => $accountId, 'provider_code' => null, 'tipo_negocio' => null,
            'model' => LeadChargeRuleModel::MODEL_FIXED, 'value' => 80, 'is_active' => true,
        ], true);
        $propertyId = (int) model(PropertyModel::class)->insert([
            'account_id' => $accountId, 'titulo' => 'Imovel Fatura', 'tipo_negocio' => 'VENDA', 'tipo_imovel' => 'CASA',
            'preco' => 500000, 'cidade' => 'Chapecó', 'bairro' => 'Centro', 'estado' => 'SC', 'status' => 'ACTIVE',
        ], true);

        $ids = [];
        for ($i = 0; $i < $leads; $i++) {
            $leadId = (int) model(\App\Models\LeadModel::class)->insert([
                'property_id' => $propertyId, 'account_id_anunciante' => $accountId, 'nome_visitante' => "Visitante {$i}",
                'email_visitante' => "fatura_{$i}_" . bin2hex(random_bytes(3)) . '@teste.local', 'tipo_lead' => 'MSG',
                'origem' => 'SITE', 'status' => 'NOVO', 'tipo_negocio' => 'VENDA',
            ], true);
            $ids[] = (int) model(LeadChargeModel::class)->insert([
                'account_id' => $accountId, 'provider_code' => null, 'lead_id' => $leadId, 'property_id' => $propertyId,
                'rule_id' => $ruleId, 'tipo_negocio' => 'VENDA', 'origem' => LeadChargeModel::ORIGEM_LEAD_RECEBIDO,
                'periodo' => self::OUTUBRO, 'base_value' => 0, 'commission_value' => 80, 'status' => LeadChargeModel::STATUS_APPROVED,
            ], true);
        }

        return [$tenant, $ids];
    }

    public function testEmiteUmaCobrancaComMensalidadeELeadsVencendoDia10(): void
    {
        $this->ativarGateway();
        [$tenant, $ids] = $this->conta(3);

        $r = (new MonthlyInvoiceService())->emitir((int) $tenant['account']->id, self::COMPETENCIA);

        $this->assertSame('emitida', $r['status']);
        $this->assertSame(1230.0, $r['total'], 'R$ 990 da mensalidade + 3 × R$ 80');
        $this->assertCount(1, FakePaymentGateway::$paymentsCreated, 'uma cobrança só');

        $pagamento = FakePaymentGateway::$paymentsCreated[0];
        $this->assertSame('UNDEFINED', $pagamento['data']['billing_type'], 'pagador escolhe PIX ou boleto');
        $this->assertSame('2026-11-10', $pagamento['data']['due_date']);
        $this->assertStringContainsString('Mensalidade do plano Prata — Presença (novembro/2026): R$ 990,00', $pagamento['data']['description']);
        $this->assertStringContainsString('Leads recebidos (outubro/2026): 3 contatos = R$ 240,00', $pagamento['data']['description']);

        $tx = model(PaymentTransactionModel::class)->find($r['payment_transaction_id']);
        $this->assertSame(MonthlyInvoiceService::TIPO, $tx['type']);
        $this->assertSame('2026-11-10', substr((string) $tx['due_date'], 0, 10));
        foreach ($ids as $id) {
            $charge = model(LeadChargeModel::class)->find($id);
            $this->assertSame(LeadChargeModel::STATUS_INVOICED, $charge->status);
            $this->assertSame((int) $tx['id'], (int) $charge->payment_transaction_id, 'cada lead ligado à fatura');
        }
    }

    public function testEmitirDuasVezesNaoCobraDeNovo(): void
    {
        $this->ativarGateway();
        [$tenant] = $this->conta(2);
        $service = new MonthlyInvoiceService();

        $primeira = $service->emitir((int) $tenant['account']->id, self::COMPETENCIA);
        $segunda  = $service->emitir((int) $tenant['account']->id, self::COMPETENCIA);

        $this->assertSame('ja_emitida', $segunda['status']);
        $this->assertSame($primeira['payment_transaction_id'], $segunda['payment_transaction_id']);
        $this->assertCount(1, FakePaymentGateway::$paymentsCreated);
    }

    public function testPagamentoConfirmadoQuitaLeadsEMensalidadeSemExpirarAAssinatura(): void
    {
        $this->ativarGateway();
        [$tenant, $ids] = $this->conta(2);
        $r  = (new MonthlyInvoiceService())->emitir((int) $tenant['account']->id, self::COMPETENCIA);
        $tx = model(PaymentTransactionModel::class)->find($r['payment_transaction_id']);

        (new WebhookService())->processEvent('fake', [
            'event_type' => 'PAYMENT_CONFIRMED', 'reference_id' => $tx['gateway_transaction_id'], 'status' => 'CONFIRMED', 'data' => [],
        ]);

        foreach ($ids as $id) {
            $this->assertSame(LeadChargeModel::STATUS_PAID, model(LeadChargeModel::class)->find($id)->status);
        }
        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertSame('ACTIVE', $sub->status);
        $this->assertSame('2026-11-30', substr((string) $sub->mensalidade_paga_ate, 0, 10));
        $this->assertNull($sub->data_fim, 'fatura da plataforma não grava data de expiração');

        // Dezembro: mensalidade de dezembro de novo, sem os leads já pagos.
        FakePaymentGateway::$paymentsCreated = [];
        $dez = (new MonthlyInvoiceService())->emitir((int) $tenant['account']->id, '2026-12-01');
        $this->assertSame(990.0, $dez['total']);
    }

    public function testMensalidadeJaPagaNoPeriodoEntraSoOsLeads(): void
    {
        $this->ativarGateway();
        [$tenant] = $this->conta(1, ['mensalidade_paga_ate' => '2026-11-30']);

        $r = (new MonthlyInvoiceService())->emitir((int) $tenant['account']->id, self::COMPETENCIA);

        $this->assertSame(80.0, $r['total']);
        $this->assertStringNotContainsString('Mensalidade', FakePaymentGateway::$paymentsCreated[0]['data']['description']);
    }

    public function testRecorrenciaAntigaNaAsaasCobraSoOsLeads(): void
    {
        $this->ativarGateway();
        [$tenant] = $this->conta(2, ['asaas_subscription_id' => 'sub_antiga_123']);

        $r = (new MonthlyInvoiceService())->emitir((int) $tenant['account']->id, self::COMPETENCIA);

        $this->assertSame(160.0, $r['total'], 'mensalidade segue na recorrência da Asaas');
    }

    public function testCarenciaNegociadaSemMensalidadeEComLeadsSoNaSegundaFase(): void
    {
        $this->ativarGateway();
        // Isenção total: começou em setembro, 6 meses sem nada → novembro isento de tudo.
        [$isento, $idsIsento] = $this->conta(0, ['ramp_started_at' => '2026-09-01', 'isencao_total_meses' => 6, 'isencao_mensalidade_meses' => 6, 'payment_method' => 'FREE']);
        $this->assertSame('nada', (new MonthlyInvoiceService())->emitir((int) $isento['account']->id, self::COMPETENCIA)['status']);

        // Só leads: começou em março, mês 9 → mensalidade zero, leads cobrados.
        [$soLeads] = $this->conta(2, ['ramp_started_at' => '2026-03-01', 'isencao_total_meses' => 6, 'isencao_mensalidade_meses' => 6, 'payment_method' => 'FREE']);
        $r = (new MonthlyInvoiceService())->emitir((int) $soLeads['account']->id, self::COMPETENCIA);

        $this->assertSame(160.0, $r['total']);
        $this->assertStringNotContainsString('Mensalidade', FakePaymentGateway::$paymentsCreated[0]['data']['description']);
    }

    public function testAssinaturaGratuitaSemPrazoNaoPassaASerCobrada(): void
    {
        $this->ativarGateway();
        [$tenant] = $this->conta(0, ['payment_method' => 'FREE', 'ramp_started_at' => null]);

        $this->assertSame('nada', (new MonthlyInvoiceService())->emitir((int) $tenant['account']->id, self::COMPETENCIA)['status']);
        $this->assertSame([], FakePaymentGateway::$paymentsCreated);
    }

    public function testFalhaNoGatewayNaoFaturaNada(): void
    {
        $this->ativarGateway(FailingPaymentGateway::class);
        [$tenant, $ids] = $this->conta(2);

        try {
            (new MonthlyInvoiceService())->emitir((int) $tenant['account']->id, self::COMPETENCIA);
            $this->fail('deveria propagar a falha do gateway');
        } catch (\Throwable $e) {
        }

        foreach ($ids as $id) {
            $this->assertSame(LeadChargeModel::STATUS_APPROVED, model(LeadChargeModel::class)->find($id)->status, 'tentável de novo');
        }
        $this->assertSame(0, model(PaymentTransactionModel::class)->where('account_id', $tenant['account']->id)->where('type', MonthlyInvoiceService::TIPO)->countAllResults());
    }

    public function testFaturaMensalEmAtrasoSuspendeAConta(): void
    {
        $this->ativarGateway();
        [$tenant] = $this->conta(1);
        $r = (new MonthlyInvoiceService())->emitir((int) $tenant['account']->id, self::COMPETENCIA);
        model(PaymentTransactionModel::class)->update($r['payment_transaction_id'], [
            'status' => 'OVERDUE', 'due_date' => date('Y-m-d', strtotime('-' . (PaymentTransactionModel::DIAS_ATRASO_SUSPENSAO + 1) . ' days')),
        ]);

        $this->assertTrue((new PaymentTransactionModel())->isAccountBlockedByOverdue((int) $tenant['account']->id));
    }

    public function testComandoDryRunNaoCobra(): void
    {
        $this->ativarGateway();
        $this->conta(1);

        command('cobrancas:emitir-faturas --competencia ' . self::COMPETENCIA . ' --dry-run');

        $this->assertSame([], FakePaymentGateway::$paymentsCreated);
    }

    public function testPainelMostraAFaturaAbertaEOsContatosCobrados(): void
    {
        $this->ativarGateway();
        [$tenant, $ids] = $this->conta(2);
        $r = (new MonthlyInvoiceService())->emitir((int) $tenant['account']->id, self::COMPETENCIA);

        $assinatura = html_entity_decode($this->actingAs($tenant['user'])->get('admin/subscription')->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('Fatura em aberto — R$ 1.150,00, vence em 10/11/2026', $assinatura);
        $this->assertStringContainsString('Leads recebidos (outubro/2026): 2 contatos', $assinatura);
        $this->assertStringContainsString('Pagar (PIX ou boleto)', $assinatura);
        $this->assertStringContainsString('minhas-cobrancas?fatura=' . $r['payment_transaction_id'], $assinatura);
        $this->assertStringNotContainsString('btn btn-outline-primary btn-change-payment-method', $assinatura, 'sem botão de trocar forma: o pagador escolhe na fatura');

        $faturas = html_entity_decode($this->actingAs($tenant['user'])->get('admin/subscription/invoices')->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('Fatura mensal', $faturas);
        $this->assertStringContainsString('PIX ou boleto', $faturas);

        $contatos = html_entity_decode($this->actingAs($tenant['user'])->get('admin/minhas-cobrancas?fatura=' . $r['payment_transaction_id'])->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('Contatos cobrados nesta fatura', $contatos);
        $this->assertStringContainsString('Visitante 0', $contatos);
        $this->assertStringContainsString('Visitante 1', $contatos);
        $this->assertStringContainsString('Imovel Fatura', $contatos);
    }

    public function testFaturaDeOutraContaNaoAbre(): void
    {
        $this->ativarGateway();
        [$dono] = $this->conta(1);
        $r = (new MonthlyInvoiceService())->emitir((int) $dono['account']->id, self::COMPETENCIA);
        [$outro] = $this->conta(0);

        $this->actingAs($outro['user'])->get('admin/minhas-cobrancas?fatura=' . $r['payment_transaction_id'])
            ->assertRedirectTo('admin/minhas-cobrancas');
    }
}
