<?php

namespace Tests\Feature;

use App\Models\LeadChargeModel;
use App\Models\PaymentTransactionModel;
use App\Models\PromotionPackageModel;
use App\Models\PropertyModel;
use App\PaymentGateways\AsaasGateway;
use App\Services\PaymentService;
use App\Services\PromotionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

final class PaymentSyncRecoveryTest extends HabitawebTestCase
{
    #[DataProvider('recoverableStatuses')]
    public function testPaidGatewayPaymentRecoversEveryBlockingLocalStatus(string $localStatus): void
    {
        $tenant = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;
        $paymentId = 'pay_recovery_' . strtolower($localStatus) . '_' . uniqid();

        $this->db->table('subscriptions')->where('id', $tenant['subscription']->id)->update([
            'asaas_customer_id'     => 'cus_recovery',
            'asaas_subscription_id' => 'sub_recovery',
            'status'                => 'OVERDUE',
        ]);

        $transactions = new PaymentTransactionModel();
        $transactions->insert([
            'account_id'             => $accountId,
            'subscription_id'        => $tenant['subscription']->id,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => $paymentId,
            'amount'                 => 100,
            'status'                 => $localStatus,
            'due_date'               => date('Y-m-d', strtotime('-10 days')),
        ]);

        cache()->save('home_featured', ['stale'], 300);

        $gateway = new class($paymentId) extends AsaasGateway {
            public function __construct(private string $paymentId) {}

            public function getPendingPayments(string $customerId): array
            {
                return [[
                    'payment_id'        => $this->paymentId,
                    'status'            => 'RECEIVED',
                    'amount'            => 100,
                    'billing_type'      => 'PIX',
                    'dueDate'           => date('Y-m-d'),
                    'invoice_url'       => null,
                    'description'       => 'Mensalidade',
                    'subscription'      => 'sub_recovery',
                ]];
            }
        };

        $service = new PaymentService();
        $property = new \ReflectionProperty($service, 'activeGateway');
        $property->setValue($service, $gateway);
        $service->syncPendingPayments($accountId);

        $transaction = $transactions->where('gateway_transaction_id', $paymentId)->first();
        $this->assertSame('SUCCESS', $transaction['status']);
        $this->assertSame([], $transactions->getOverdueAccountIdsCached(3));
        $this->assertNull(cache('home_featured'));
    }

    public static function recoverableStatuses(): array
    {
        return [
            'pending'          => ['PENDING'],
            'awaiting payment' => ['AWAITING_PAYMENT'],
            'overdue'          => ['OVERDUE'],
        ];
    }

    /**
     * O sync marcava a transação como paga e ativava a assinatura, mas nunca
     * aplicava o efeito do TIPO — uma cobrança de TURBO ou LEAD_INVOICE
     * recuperada pelo sync (porque o webhook falhou em ser entregue, o caso
     * mais comum) ficava com o status pago na tela sem nenhum efeito real:
     * a turbinada nunca ativava, a fatura de leads nunca fechava.
     */
    public function testSyncBaixaFaturaDeLeadsETurbo(): void
    {
        $tenant    = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;

        $this->db->table('subscriptions')->where('id', $tenant['subscription']->id)->update([
            'asaas_customer_id' => 'cus_settle_' . uniqid(),
        ]);

        // ------------------------------------------------------- fatura de leads
        $leadInvoiceId = 'pay_lead_invoice_' . uniqid();

        $property = model(PropertyModel::class)->find(model(PropertyModel::class)->insert([
            'account_id'   => $accountId,
            'titulo'       => 'Imovel Sync',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 500000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true));

        $leadId = (int) model(\App\Models\LeadModel::class)->insert([
            'property_id'           => $property->id,
            'account_id_anunciante' => $accountId,
            'nome_visitante'        => 'Visitante Sync',
            'email_visitante'       => 'sync_' . bin2hex(random_bytes(3)) . '@teste.local',
            'tipo_lead'             => 'MSG',
            'origem'                => 'SITE',
            'status'                => 'NOVO',
            'tipo_negocio'          => 'VENDA',
        ], true);

        $leadInvoiceTxId = (int) (new PaymentTransactionModel())->insert([
            'account_id'             => $accountId,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => $leadInvoiceId,
            'amount'                 => 80,
            'status'                 => 'PENDING',
            'type'                   => 'LEAD_INVOICE',
            'due_date'               => date('Y-m-d'),
        ], true);

        $chargeId = (int) model(LeadChargeModel::class)->insert([
            'account_id'             => $accountId,
            'lead_id'                => $leadId,
            'property_id'            => $property->id,
            'tipo_negocio'           => 'VENDA',
            'origem'                 => LeadChargeModel::ORIGEM_LEAD_RECEBIDO,
            'periodo'                => date('Y-m-01'),
            'commission_value'       => 80,
            'status'                 => LeadChargeModel::STATUS_INVOICED,
            'payment_transaction_id' => $leadInvoiceTxId,
        ], true);

        // -------------------------------------------------------------- turbo
        $turboPaymentId = 'pay_turbo_' . uniqid();

        $packageId = (int) model(PromotionPackageModel::class)->insert([
            'chave'         => 'TURBO_SYNC_' . bin2hex(random_bytes(3)),
            'nome'          => 'Turbo Sync',
            'tipo_promocao' => PromotionService::TIPO_TURBO,
            'duracao_dias'  => 7,
            'preco'         => 50.00,
        ], true);
        $package = model(PromotionPackageModel::class)->find($packageId);

        (new PaymentTransactionModel())->insert([
            'account_id'             => $accountId,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => $turboPaymentId,
            'amount'                 => 50,
            'status'                 => 'PENDING',
            'type'                   => 'TURBO',
            'due_date'               => date('Y-m-d'),
            'metadata'               => json_encode(['promo_key' => $package->chave, 'property_id' => $property->id]),
        ]);

        // -------------------------------------------------------------- sync
        $gateway = new class($leadInvoiceId, $turboPaymentId) extends AsaasGateway {
            public function __construct(private string $leadInvoiceId, private string $turboPaymentId) {}

            public function getPendingPayments(string $customerId): array
            {
                return [
                    [
                        'payment_id'   => $this->leadInvoiceId,
                        'status'       => 'RECEIVED',
                        'amount'       => 80,
                        'billing_type' => 'PIX',
                        'dueDate'      => date('Y-m-d'),
                        'invoice_url'  => null,
                        'description'  => 'Cobrança de leads recebidos',
                    ],
                    [
                        'payment_id'   => $this->turboPaymentId,
                        'status'       => 'RECEIVED',
                        'amount'       => 50,
                        'billing_type' => 'PIX',
                        'dueDate'      => date('Y-m-d'),
                        'invoice_url'  => null,
                        'description'  => 'Turbinar imóvel',
                    ],
                ];
            }
        };

        $service  = new PaymentService();
        $property2 = new \ReflectionProperty($service, 'activeGateway');
        $property2->setValue($service, $gateway);
        $service->syncPendingPayments($accountId);

        $this->assertSame(
            LeadChargeModel::STATUS_PAID,
            model(LeadChargeModel::class)->find($chargeId)->status,
            'fatura de leads recuperada pelo sync precisa fechar a cobranca'
        );

        $imovelAtualizado = model(PropertyModel::class)->find($property->id);
        $this->assertGreaterThan(0, (int) $imovelAtualizado->highlight_level, 'turbinada recuperada pelo sync precisa ativar o destaque');
    }
}
