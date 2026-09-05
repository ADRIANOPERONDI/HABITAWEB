<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\PaymentTransactionModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * `/admin/subscription` e `/admin/subscription/invoices` — a página que o
 * tenant vê o estado da própria assinatura. Cobre especificamente o
 * escopo por tipo de transação (C6): uma fatura de leads ou turbinada
 * pendente/vencida não é a mesma coisa que a mensalidade em risco.
 *
 * @internal
 */
final class SubscriptionPageTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /**
     * `getLastPendingTransactionByAccount()` (usado por
     * `SubscriptionController::index()`) passa a ignorar LEAD_INVOICE —
     * antes disso, uma fatura de leads pendente virava o mesmo badge
     * "Pagamento Pendente" e o mesmo aviso de assinatura em risco que uma
     * mensalidade de verdade em atraso, o que não é o caso: a mensalidade
     * está em dia, só a cobrança de leads é que está pendente.
     */
    public function testFaturaDeLeadPendenteNaoDisparaAlertaDeAssinatura(): void
    {
        $tenant = (new TenantFactory())->create();

        model(PaymentTransactionModel::class)->insert([
            'account_id'             => $tenant['account']->id,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => 'lead_invoice_pendente_' . uniqid(),
            'amount'                 => 80.00,
            'status'                 => 'PENDING',
            'type'                   => 'LEAD_INVOICE',
            'due_date'               => date('Y-m-d', strtotime('+3 days')),
        ]);

        $html = (string) $this->actingAs($tenant['user'])->get('admin/subscription')->getBody();

        $this->assertStringNotContainsString('Pagamento Pendente', $html);
        $this->assertStringContainsString('Ativo', $html);
    }

    /**
     * A coluna "Plano/Descrição" mostrava "Assinatura" pra QUALQUER
     * transação sem plan_name — inclusive fatura de leads e turbinada,
     * escondendo o que a cobrança realmente é.
     */
    public function testFaturasRotuladasPorTipo(): void
    {
        $tenant = (new TenantFactory())->create();

        model(PaymentTransactionModel::class)->insert([
            'account_id'             => $tenant['account']->id,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => 'lead_invoice_label_' . uniqid(),
            'amount'                 => 80.00,
            'status'                 => 'PENDING',
            'type'                   => 'LEAD_INVOICE',
            'due_date'               => date('Y-m-d'),
        ]);

        model(PaymentTransactionModel::class)->insert([
            'account_id'             => $tenant['account']->id,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => 'turbo_label_' . uniqid(),
            'amount'                 => 50.00,
            'status'                 => 'SUCCESS',
            'type'                   => 'TURBO',
            'due_date'               => date('Y-m-d'),
        ]);

        $html = (string) $this->actingAs($tenant['user'])->get('admin/subscription/invoices')->getBody();

        $this->assertStringContainsString('Fatura de leads', $html);
        $this->assertStringContainsString('Turbinada', $html);
    }
}
