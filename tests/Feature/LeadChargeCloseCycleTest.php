<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\LeadChargeModel;
use App\Models\LeadChargeRuleModel;
use App\Models\PaymentTransactionModel;
use App\Models\PlanModel;
use App\Models\PropertyModel;
use App\Models\SubscriptionModel;
use App\Services\LeadChargeService;
use App\Services\LeadCreditService;
use App\Services\PaymentService;
use App\Services\WebhookService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\Fakes\FailingPaymentGateway;
use Tests\Support\Fakes\FakePaymentGateway;
use Tests\Support\HabitawebTestCase;

/**
 * Fechamento de ciclo mensal (`LeadChargeService::closeCycleForAccount`,
 * consumido por `spark leads:fechar-ciclo`) e o retorno via webhook.
 *
 * Usa um gateway fake registrado como primário dentro da própria transação
 * do teste — este ambiente não tem credencial de sandbox do Asaas.
 */
final class LeadChargeCloseCycleTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        cache()->clean();
        FakePaymentGateway::$paymentsCreated = [];
    }

    private function ativarGatewayFake(string $classeGateway = FakePaymentGateway::class): PaymentService
    {
        $db = \Config\Database::connect();
        $db->query('UPDATE payment_gateways SET is_primary = false');

        $db->table('payment_gateways')->insert([
            'code'       => 'fake_' . bin2hex(random_bytes(4)),
            'name'       => 'Fake',
            'class_name' => $classeGateway,
            'is_active'  => true,
            'is_primary' => true,
        ]);

        return new PaymentService();
    }

    /** @return array{0: array, 1: int[], 2: string, 3: int} tenant, charge_ids, periodo, property_id */
    private function contaComCargas(float $valorPorLead, int $quantidade, ?float $credito = null, ?string $periodo = null): array
    {
        $tenant = (new TenantFactory())->create();

        if ($credito !== null) {
            $planId = (int) model(PlanModel::class)->insert([
                'chave'                => 'CICLO_' . bin2hex(random_bytes(4)),
                'nome'                 => 'Plano Ciclo ' . bin2hex(random_bytes(4)),
                'preco_mensal'         => 1690.00,
                'credito_leads_mensal' => $credito,
                'ativo'                => true,
            ], true);

            model(SubscriptionModel::class)
                ->where('account_id', $tenant['account']->id)
                ->set(['plan_id' => $planId, 'status' => 'ACTIVE'])
                ->update();
        }

        $ruleId = (int) model(LeadChargeRuleModel::class)->insert([
            'account_id'    => $tenant['account']->id,
            'provider_code' => null,
            'tipo_negocio'  => null,
            'model'         => LeadChargeRuleModel::MODEL_FIXED,
            'value'         => $valorPorLead,
            'is_active'     => true,
        ], true);

        $propertyId = (int) model(PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Imovel Ciclo',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 500000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);

        $periodo ??= date('Y-m-01');
        $chargeIds = [];

        for ($i = 0; $i < $quantidade; $i++) {
            $chargeIds[] = $this->criarCargaAprovada($tenant, $propertyId, $ruleId, $valorPorLead, $periodo, $i);
        }

        return [$tenant, $chargeIds, $periodo, $propertyId];
    }

    private function criarCargaAprovada(array $tenant, int $propertyId, ?int $ruleId, float $valor, string $periodo, int $seq = 0): int
    {
        $leadId = (int) model(\App\Models\LeadModel::class)->insert([
            'property_id'           => $propertyId,
            'account_id_anunciante' => $tenant['account']->id,
            'nome_visitante'        => 'Visitante ' . $seq,
            'email_visitante'       => 'ciclo_' . $seq . '_' . bin2hex(random_bytes(3)) . '@teste.local',
            'tipo_lead'             => 'MSG',
            'origem'                => 'SITE',
            'status'                => 'NOVO',
            'tipo_negocio'          => 'VENDA',
        ], true);

        return (int) model(LeadChargeModel::class)->insert([
            'account_id'       => $tenant['account']->id,
            'provider_code'    => null,
            'lead_id'          => $leadId,
            'property_id'      => $propertyId,
            'rule_id'          => $ruleId,
            'tipo_negocio'     => 'VENDA',
            'origem'           => LeadChargeModel::ORIGEM_LEAD_RECEBIDO,
            'periodo'          => $periodo,
            'base_value'       => 0,
            'commission_value' => $valor,
            'status'           => LeadChargeModel::STATUS_APPROVED,
        ], true);
    }

    public function testFechamentoTotalmenteCobertoPorCreditoNaoTocaOGateway(): void
    {
        [$tenant, $chargeIds, $periodo] = $this->contaComCargas(80.0, 2, credito: 200.0);
        (new LeadCreditService())->grantMonthly($periodo);

        $resultado = (new LeadChargeService())->closeCycleForAccount((int) $tenant['account']->id, $periodo);

        $this->assertSame('invoiced_free', $resultado['status']);
        $this->assertSame(160.0, $resultado['total']);
        $this->assertSame(160.0, $resultado['credit_applied']);
        $this->assertSame(0.0, $resultado['charged']);
        $this->assertNull($resultado['payment_transaction_id']);
        $this->assertSame([], FakePaymentGateway::$paymentsCreated);

        foreach ($chargeIds as $id) {
            $this->assertSame(LeadChargeModel::STATUS_INVOICED, model(LeadChargeModel::class)->find($id)->status);
        }

        // Sobra do credito (200 - 160 = 40) expira, nao acumula pro mes seguinte.
        $this->assertSame(0.0, (new LeadCreditService())->balanceFor((int) $tenant['account']->id, $periodo));
    }

    public function testFechamentoParcialCobraORestanteNoGateway(): void
    {
        $this->ativarGatewayFake();
        [$tenant, $chargeIds, $periodo] = $this->contaComCargas(80.0, 3, credito: 100.0); // total 240, credito 100
        (new LeadCreditService())->grantMonthly($periodo);

        $resultado = (new LeadChargeService())->closeCycleForAccount((int) $tenant['account']->id, $periodo);

        $this->assertSame('invoiced_charged', $resultado['status']);
        $this->assertSame(240.0, $resultado['total']);
        $this->assertSame(100.0, $resultado['credit_applied']);
        $this->assertSame(140.0, $resultado['charged']);
        $this->assertNotNull($resultado['payment_transaction_id']);

        $transaction = model(PaymentTransactionModel::class)->find($resultado['payment_transaction_id']);
        $this->assertSame('LEAD_INVOICE', $transaction['type']);
        $this->assertSame(140.0, (float) $transaction['amount']);
        $this->assertSame('PENDING', $transaction['status']);

        $this->assertCount(1, FakePaymentGateway::$paymentsCreated);
        $this->assertSame(140.0, FakePaymentGateway::$paymentsCreated[0]['amount']);

        foreach ($chargeIds as $id) {
            $this->assertSame(LeadChargeModel::STATUS_INVOICED, model(LeadChargeModel::class)->find($id)->status);
        }
    }

    public function testSemCreditoCobraOTotalNoGateway(): void
    {
        $this->ativarGatewayFake();
        [$tenant, , $periodo] = $this->contaComCargas(80.0, 2);

        $resultado = (new LeadChargeService())->closeCycleForAccount((int) $tenant['account']->id, $periodo);

        $this->assertSame('invoiced_charged', $resultado['status']);
        $this->assertSame(160.0, $resultado['charged']);
        $this->assertSame(0.0, $resultado['credit_applied']);
    }

    public function testSemCobrancaAprovadaNoPeriodoNaoFazNada(): void
    {
        $tenant = (new TenantFactory())->create();

        $resultado = (new LeadChargeService())->closeCycleForAccount((int) $tenant['account']->id, date('Y-m-01'));

        $this->assertSame('nothing', $resultado['status']);
    }

    /** Rodar o fechamento duas vezes não fatura de novo — só APPROVED entra na conta. */
    public function testFecharDuasVezesNaoRefaturaOQueJaFoiInvoiced(): void
    {
        [$tenant, , $periodo] = $this->contaComCargas(80.0, 1, credito: 200.0);
        (new LeadCreditService())->grantMonthly($periodo);

        $service = new LeadChargeService();
        $service->closeCycleForAccount((int) $tenant['account']->id, $periodo);
        $segundo = $service->closeCycleForAccount((int) $tenant['account']->id, $periodo);

        $this->assertSame('nothing', $segundo['status']);
    }

    public function testWebhookDePagamentoConfirmadoMarcaCobrancasComoPagas(): void
    {
        $this->ativarGatewayFake();
        [$tenant, $chargeIds, $periodo] = $this->contaComCargas(80.0, 2);

        $resultado = (new LeadChargeService())->closeCycleForAccount((int) $tenant['account']->id, $periodo);
        $transaction = model(PaymentTransactionModel::class)->find($resultado['payment_transaction_id']);

        (new WebhookService())->processEvent('fake', [
            'event_type'   => 'PAYMENT_CONFIRMED',
            'reference_id' => $transaction['gateway_transaction_id'],
            'status'       => 'CONFIRMED',
            'data'         => [],
        ]);

        foreach ($chargeIds as $id) {
            $this->assertSame(LeadChargeModel::STATUS_PAID, model(LeadChargeModel::class)->find($id)->status);
        }
    }

    // ------------------------------------------------------- C5: robustez

    private function runCloseCycleCommand(string $args = ''): void
    {
        ob_start();
        command('leads:fechar-ciclo ' . $args);
        ob_end_clean();
    }

    /**
     * Sem `--periodo`, o comando fecha TODO período atrasado, não só o mês
     * passado — uma aprovação tardia (`leads:aprovar-cobrancas` rodando
     * depois do fechamento já ter passado) ou um cron fora do ar por dias
     * não pode deixar cobrança presa pra sempre esperando alguém lembrar de
     * rodar com `--periodo` na mão.
     */
    public function testFechaPeriodosAntigosComAprovadasTardias(): void
    {
        $this->ativarGatewayFake();

        $periodoAntigo = date('Y-m-01', strtotime('-3 months'));
        [, $chargeIdsAntigo] = $this->contaComCargas(80.0, 1, periodo: $periodoAntigo);

        $periodoPassado = date('Y-m-01', strtotime('-1 month'));
        [, $chargeIdsPassado] = $this->contaComCargas(80.0, 1, periodo: $periodoPassado);

        $this->runCloseCycleCommand(); // sem --periodo

        foreach ([...$chargeIdsAntigo, ...$chargeIdsPassado] as $id) {
            $this->assertSame(
                LeadChargeModel::STATUS_INVOICED,
                model(LeadChargeModel::class)->find($id)->status,
                'periodo antigo nao pode ficar preso so porque nao e o mes passado'
            );
        }
    }

    /**
     * Falha na chamada ao gateway não pode deixar o crédito consumido sem
     * nenhuma cobrança real ter acontecido — a conta perderia o crédito do
     * mês de graça. O saldo e o status das cobranças precisam voltar
     * exatamente ao que eram antes da tentativa.
     *
     * `HabitawebTestCase` já embrulha cada teste numa transação (rollback no
     * tearDown), e o `transStart()`/`transRollback()` PRÓPRIO de
     * `closeCycleForAccount()` vira só um contador de profundidade quando
     * está aninhado (CI4 só executa o ROLLBACK de verdade na transação mais
     * externa — ver `BaseConnection::transRollback()`). Sem "destravar" isso
     * primeiro, o rollback do serviço seria inerte e este teste não provaria
     * nada. Por isso commita a montagem do cenário antes de testar a falha, e
     * limpa manualmente no `finally` (apagar `accounts` já casata tudo que
     * depende dela — subscriptions, properties, leads, lead_charges,
     * lead_charge_rules, lead_credit_ledger).
     */
    public function testFalhaNoGatewayDesfazODebitoDeCredito(): void
    {
        $this->ativarGatewayFake(FailingPaymentGateway::class);
        [$tenant, $chargeIds, $periodo] = $this->contaComCargas(80.0, 2, credito: 100.0);
        (new LeadCreditService())->grantMonthly($periodo);

        $accountId = (int) $tenant['account']->id;
        $planId    = (int) model(SubscriptionModel::class)->where('account_id', $accountId)->first()->plan_id;

        $this->db->transCommit();

        try {
            try {
                (new LeadChargeService())->closeCycleForAccount($accountId, $periodo);
                $this->fail('deveria ter propagado a falha do gateway');
            } catch (\RuntimeException $e) {
                $this->assertSame('Falha simulada no gateway.', $e->getMessage());
            }

            $this->assertSame(
                100.0,
                (new LeadCreditService())->balanceFor($accountId, $periodo),
                'o debito de credito tem que ser desfeito junto com a falha'
            );

            foreach ($chargeIds as $id) {
                $this->assertSame(
                    LeadChargeModel::STATUS_APPROVED,
                    model(LeadChargeModel::class)->find($id)->status,
                    'nada pode ficar INVOICED sem cobranca real nenhuma'
                );
            }
        } finally {
            model(\App\Models\AccountModel::class)->delete($accountId, true);
            model(PlanModel::class)->delete($planId, true);
            $this->db->transStart();
        }
    }

    /**
     * Uma aprovação tardia no MESMO período depois que a fatura já foi
     * gerada (cenário real: `leads:aprovar-cobrancas` aprova mais um lead
     * daquele mês só depois do primeiro fechamento) precisa entrar na fatura
     * já existente, não criar uma segunda cobrança no gateway.
     */
    public function testNaoCriaSegundaFaturaParaOMesmoPeriodo(): void
    {
        $this->ativarGatewayFake();
        [$tenant, $chargeIds, $periodo, $propertyId] = $this->contaComCargas(80.0, 1);

        $primeiro = (new LeadChargeService())->closeCycleForAccount((int) $tenant['account']->id, $periodo);
        $this->assertSame('invoiced_charged', $primeiro['status']);
        $this->assertCount(1, FakePaymentGateway::$paymentsCreated);

        $novoChargeId = $this->criarCargaAprovada($tenant, $propertyId, null, 80.0, $periodo, 99);

        $segundo = (new LeadChargeService())->closeCycleForAccount((int) $tenant['account']->id, $periodo);

        $this->assertSame('invoiced_charged', $segundo['status']);
        $this->assertSame(
            $primeiro['payment_transaction_id'],
            $segundo['payment_transaction_id'],
            'reaproveita a fatura existente, nao cria outra'
        );
        $this->assertCount(1, FakePaymentGateway::$paymentsCreated, 'nao pode ter chamado o gateway de novo');
        $this->assertSame(LeadChargeModel::STATUS_INVOICED, model(LeadChargeModel::class)->find($novoChargeId)->status);

        foreach ($chargeIds as $id) {
            $this->assertSame(LeadChargeModel::STATUS_INVOICED, model(LeadChargeModel::class)->find($id)->status);
        }
    }

    /**
     * O customer criado no gateway na primeira cobrança de lead precisa
     * ficar salvo na assinatura — sem isso, toda cobrança de lead futura
     * desta conta criaria um customer novo (o caso comum é uma conta em
     * rampa gratuita, Fase 6, que chega aqui sem `asaas_customer_id` porque
     * nunca pagou mensalidade nenhuma).
     */
    public function testGuardaOClienteDoGatewayNaAssinatura(): void
    {
        $this->ativarGatewayFake();
        [$tenant, , $periodo] = $this->contaComCargas(80.0, 1);

        $antes = model(SubscriptionModel::class)
            ->where('account_id', $tenant['account']->id)
            ->orderBy('id', 'DESC')
            ->first();
        $this->assertEmpty($antes->asaas_customer_id, 'conta nova nao tem customer no gateway ainda');

        (new LeadChargeService())->closeCycleForAccount((int) $tenant['account']->id, $periodo);

        $depois = model(SubscriptionModel::class)
            ->where('account_id', $tenant['account']->id)
            ->orderBy('id', 'DESC')
            ->first();
        $this->assertNotEmpty($depois->asaas_customer_id, 'o customer criado na cobranca de lead precisa sobreviver na assinatura');
    }
}
