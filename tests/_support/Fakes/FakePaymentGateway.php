<?php

namespace Tests\Support\Fakes;

use App\PaymentGateways\GatewayInterface;

/**
 * Gateway de pagamento fake para testar o fechamento de fatura de lead
 * (`LeadChargeService::closeCycleForAccount`) sem rede — este ambiente não
 * tem chave de sandbox do Asaas (ver CLAUDE.md/decisão de sessão: testes que
 * dependem do sandbox real ficam fora do escopo desde a Fase 1).
 *
 * Registrar como `payment_gateways` primário dentro de um teste, dentro da
 * transação de `HabitawebTestCase`, é suficiente: o rollback ao fim do teste
 * desfaz o registro sozinho.
 */
class FakePaymentGateway implements GatewayInterface
{
    public static array $paymentsCreated = [];
    public static array $subscriptionUpdates = [];

    public function configure(array $config): void
    {
    }

    public function findCustomerByDocument(string $document): ?string
    {
        return null;
    }

    public function createCustomer(array $customerData): string
    {
        return 'fake_cus_' . substr(md5(json_encode($customerData) . microtime()), 0, 12);
    }

    public function updateCustomer(string $customerId, array $customerData): bool
    {
        return true;
    }

    public function createSubscription(string $customerId, string $planId, array $data): array
    {
        return [
            'subscription_id'    => 'fake_sub_' . uniqid(),
            'status'             => 'ACTIVE',
            'next_billing_date'  => date('Y-m-d', strtotime('+1 month')),
            'payment_url'        => null,
        ];
    }

    public function createPayment(string $customerId, float $amount, array $data): array
    {
        $paymentId = 'fake_pay_' . uniqid();

        self::$paymentsCreated[] = [
            'payment_id'  => $paymentId,
            'customer_id' => $customerId,
            'amount'      => $amount,
            'data'        => $data,
        ];

        return [
            'payment_id'  => $paymentId,
            'status'      => 'PENDING',
            'payment_url' => 'https://fake.local/pay/' . $paymentId,
            'qr_code'     => null,
        ];
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        return true;
    }

    public function cancelPayment(string $paymentId): bool
    {
        return true;
    }

    public function updateSubscription(string $subscriptionId, array $data): bool
    {
        self::$subscriptionUpdates[] = [
            'subscription_id' => $subscriptionId,
            'data'            => $data,
        ];

        return true;
    }

    public function getSubscription(string $subscriptionId): ?array
    {
        return null;
    }

    public function suspendSubscription(string $subscriptionId): bool
    {
        return true;
    }

    public function reactivateSubscription(string $subscriptionId): bool
    {
        return true;
    }

    public function handleWebhook(array $payload, array $headers = [], string $rawBody = ''): array
    {
        return ['event_type' => 'PAYMENT_CONFIRMED', 'reference_id' => 'fake', 'status' => 'CONFIRMED', 'data' => []];
    }

    public function getSupportedMethods(): array
    {
        return ['PIX', 'BOLETO'];
    }

    public function validateConfig(): bool
    {
        return true;
    }

    public function getCode(): string
    {
        return 'fake';
    }

    public function getActiveSubscription(string $customerId): ?array
    {
        return null;
    }

    public function getName(): string
    {
        return 'Fake Gateway (testes)';
    }

    public function getPendingPayments(string $customerId): array
    {
        return [];
    }
}
