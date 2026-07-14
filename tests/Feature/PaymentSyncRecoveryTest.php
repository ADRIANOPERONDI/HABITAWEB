<?php

namespace Tests\Feature;

use App\Models\PaymentTransactionModel;
use App\PaymentGateways\AsaasGateway;
use App\Services\PaymentService;
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
}
