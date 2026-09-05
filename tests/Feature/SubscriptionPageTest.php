<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\PaymentTransactionModel;
use App\Models\PlanLaunchRampModel;
use App\Models\SubscriptionModel;
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

    /**
     * D4: a barra de "Uso do Plano" (imóveis ativos — todo plano comercial
     * atual é ilimitado, então não media nada de útil) dá lugar à cota de
     * turbinada do plano e ao estágio da rampa de lançamento.
     */
    public function testMostraCotaDeTurbinadaEEstagioDaRampa(): void
    {
        // valid_from precisa estar seguramente no passado — o valor semeado
        // pela migration é a data em que `php spark migrate` rodou neste
        // ambiente, que pode ser mais recente que o "-2 meses" abaixo
        // (LaunchRampService checa valid_from/valid_to contra a data de
        // ADESÃO da conta, não contra hoje — ver LaunchRampServiceTest).
        $db = \Config\Database::connect();
        $db->table('plan_launch_ramps')->truncate();
        model(PlanLaunchRampModel::class)->insertBatch([
            ['mes_de' => 1, 'mes_ate' => 6, 'percentual' => 0, 'is_active' => true, 'valid_from' => '2020-01-01'],
            ['mes_de' => 7, 'mes_ate' => 12, 'percentual' => 50, 'is_active' => true, 'valid_from' => '2020-01-01'],
            ['mes_de' => 13, 'mes_ate' => null, 'percentual' => 100, 'is_active' => true, 'valid_from' => '2020-01-01'],
        ]);

        $tenant = (new TenantFactory())->create([], 'OURO');

        model(SubscriptionModel::class)->update($tenant['subscription']->id, [
            'ramp_started_at'    => date('Y-m-d', strtotime('-2 months')), // mes 3: 0%
            'ramp_percent_atual' => 0,
        ]);

        $response = $this->actingAs($tenant['user'])->get('admin/subscription');

        $response->assertSee('Turbinadas do plano');
        $response->assertSee('Usadas este mês');
        $response->assertSee('Rampa de lançamento: mês 3');
    }
}
