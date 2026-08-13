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

    private function ativarGatewayFake(): PaymentService
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

        return new PaymentService();
    }

    private function contaComCargas(float $valorPorLead, int $quantidade, ?float $credito = null): array
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

        $periodo   = date('Y-m-01');
        $chargeIds = [];

        for ($i = 0; $i < $quantidade; $i++) {
            $leadId = (int) model(\App\Models\LeadModel::class)->insert([
                'property_id'           => $propertyId,
                'account_id_anunciante' => $tenant['account']->id,
                'nome_visitante'        => 'Visitante ' . $i,
                'email_visitante'       => 'ciclo_' . $i . '_' . bin2hex(random_bytes(3)) . '@teste.local',
                'tipo_lead'             => 'MSG',
                'origem'                => 'SITE',
                'status'                => 'NOVO',
                'tipo_negocio'          => 'VENDA',
            ], true);

            $chargeId = (int) model(LeadChargeModel::class)->insert([
                'account_id'       => $tenant['account']->id,
                'provider_code'    => null,
                'lead_id'          => $leadId,
                'property_id'      => $propertyId,
                'rule_id'          => $ruleId,
                'tipo_negocio'     => 'VENDA',
                'origem'           => LeadChargeModel::ORIGEM_LEAD_RECEBIDO,
                'periodo'          => $periodo,
                'base_value'       => 0,
                'commission_value' => $valorPorLead,
                'status'           => LeadChargeModel::STATUS_APPROVED,
            ], true);

            $chargeIds[] = $chargeId;
        }

        return [$tenant, $chargeIds, $periodo];
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
}
