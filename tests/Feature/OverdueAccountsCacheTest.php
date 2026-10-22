<?php

namespace Tests\Feature;

use App\Models\PaymentTransactionModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre o contrato de cache de PaymentTransactionModel::getOverdueAccountIdsCached():
 * (1) lista vazia também é cacheada (o caso comum "ninguém inadimplente" é o que
 * mais precisa de cache — [] !== null), (2) resultado não-vazio vem do cache na
 * segunda chamada, (3) upsertTransaction() invalida o cache — sem isso, uma conta
 * que quitou a fatura continuaria bloqueada nas buscas públicas por até 120s a
 * mais do que o devido (e vice-versa: recém-inadimplente continuaria visível).
 */
final class OverdueAccountsCacheTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        cache()->delete('overdue_account_ids_' . PaymentTransactionModel::DIAS_ATRASO_SUSPENSAO);
    }

    protected function tearDown(): void
    {
        cache()->delete('overdue_account_ids_' . PaymentTransactionModel::DIAS_ATRASO_SUSPENSAO);
        parent::tearDown();
    }

    public function testEmptyListIsCachedAndReused(): void
    {
        $model = new PaymentTransactionModel();

        $this->assertSame([], $model->getOverdueAccountIdsCached());

        // Semeia o cache manualmente com um sentinela: se a 2ª chamada fosse ao
        // banco, retornaria [] — receber o sentinela prova que veio do cache.
        cache()->save('overdue_account_ids_' . PaymentTransactionModel::DIAS_ATRASO_SUSPENSAO, [999888], 120);
        $this->assertSame([999888], $model->getOverdueAccountIdsCached());
    }

    public function testOverdueAccountAppearsAndUpsertInvalidatesCache(): void
    {
        $tenant = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;

        $model = new PaymentTransactionModel();

        // Aquece o cache ANTES da fatura vencida existir → lista vazia cacheada.
        $this->assertSame([], $model->getOverdueAccountIdsCached());

        // upsertTransaction (o choke point de todo webhook de gateway) cria a
        // fatura vencida E deve invalidar o cache no mesmo ato.
        $result = $model->upsertTransaction([
            'account_id'             => $accountId,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => 'e2e_overdue_' . uniqid(),
            'amount'                 => 100.00,
            'status'                 => 'OVERDUE',
            'due_date'               => date('Y-m-d', strtotime('-10 days')),
        ]);

        $this->assertNotFalse($result, 'upsertTransaction falhou: ' . json_encode($model->errors()));
        $this->assertContains($accountId, $model->getOverdueAccountIdsCached());
    }

    /**
     * P3: só fatura de ASSINATURA bloqueia. Uma fatura de leads (ou
     * turbinada) vencida é uma cobrança à parte — não pode derrubar o
     * catálogo/painel do tenant, que continua com a mensalidade em dia.
     */
    public function testFaturaDeLeadVencidaNaoBloqueiaOCatalogo(): void
    {
        $tenant    = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;

        $model = new PaymentTransactionModel();
        $model->insert([
            'account_id'             => $accountId,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => 'lead_invoice_overdue_' . uniqid(),
            'amount'                 => 80.00,
            'status'                 => 'OVERDUE',
            'type'                   => 'LEAD_INVOICE',
            'due_date'               => date('Y-m-d', strtotime('-10 days')),
        ]);

        $this->assertFalse($model->isAccountBlockedByOverdue($accountId));
        $this->assertNotContains($accountId, $model->getOverdueAccountIds());
    }

    /** Renovação criada pela Asaas (RECURRING_CHARGE) em atraso suspende como a mensalidade. */
    public function testRenovacaoRecorrenteEmAtrasoSuspende(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;

        $model = new PaymentTransactionModel();
        $model->insert([
            'account_id'             => $accountId,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => 'recurring_overdue_' . uniqid(),
            'amount'                 => 990.00,
            'status'                 => 'OVERDUE',
            'type'                   => 'RECURRING_CHARGE',
            'due_date'               => date('Y-m-d', strtotime('-10 days')),
        ]);

        $this->assertTrue($model->isAccountBlockedByOverdue($accountId));
    }

    /**
     * Ativar a assinatura (pagamento confirmado via sync) cancelava localmente
     * as faturas de leads e de turbinada em aberto — elas continuavam na
     * Asaas, mas o pagamento delas nunca mais dava baixa.
     */
    public function testAtivarAssinaturaNaoCancelaFaturaDeLeadsNemTurbinada(): void
    {
        $tenant    = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;
        model(\App\Models\SubscriptionModel::class)->update($tenant['subscription']->id, ['asaas_subscription_id' => 'sub_ativar_' . uniqid()]);
        $sub = model(\App\Models\SubscriptionModel::class)->find($tenant['subscription']->id);

        $model = new PaymentTransactionModel();
        $lead  = $model->insert(['account_id' => $accountId, 'gateway' => 'asaas', 'gateway_transaction_id' => 'lead_' . uniqid(), 'amount' => 80, 'status' => 'PENDING', 'type' => 'LEAD_INVOICE'], true);
        $turbo = $model->insert(['account_id' => $accountId, 'gateway' => 'asaas', 'gateway_transaction_id' => 'turbo_' . uniqid(), 'amount' => 50, 'status' => 'PENDING', 'type' => 'TURBO'], true);
        $velha = $model->insert(['account_id' => $accountId, 'gateway' => 'asaas', 'gateway_transaction_id' => 'velha_' . uniqid(), 'amount' => 990, 'status' => 'PENDING', 'type' => 'SUBSCRIPTION'], true);

        (new \App\Services\PaymentService())->activateSubscriptionByAsaasId((string) $sub->asaas_subscription_id);

        $this->assertSame('PENDING', $model->find($lead)['status']);
        $this->assertSame('PENDING', $model->find($turbo)['status']);
        $this->assertSame('CANCELLED', $model->find($velha)['status'], 'mensalidade antiga de outra assinatura continua sendo limpa');
    }
}
