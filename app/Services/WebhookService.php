<?php

namespace App\Services;

use App\Models\AccountModel;
use App\Models\SubscriptionModel;
use App\Models\PaymentTransactionModel;
use App\Models\PaymentProfileModel;
use App\Models\WebhookLogModel;

/**
 * Service to handle business logic triggered by webhooks from different gateways.
 */
class WebhookService
{
    protected $accountModel;
    protected $subscriptionModel;
    protected $transactionModel;
    protected $profileModel;
    protected $webhookLogModel;
    protected $integrationWebhookModel;

    public function __construct()
    {
        $this->accountModel = model(AccountModel::class);
        $this->subscriptionModel = model(SubscriptionModel::class);
        $this->transactionModel = model(PaymentTransactionModel::class);
        $this->profileModel = model(PaymentProfileModel::class);
        $this->webhookLogModel = model(WebhookLogModel::class);
        $this->integrationWebhookModel = model(\App\Models\IntegrationWebhookModel::class);
    }

    /**
     * Dispatch an event to external webhooks registered by the account.
     * 
     * @param string $event The event name (e.g., 'lead.created')
     * @param array $payload The data to send
     * @param int $accountId The account that owns the data
     */
    public function dispatch(string $event, array $payload, int $accountId)
    {
        // 1. Find active webhooks for this event and account
        $webhooks = $this->integrationWebhookModel->where('account_id', $accountId)
                                                  ->where('event', $event)
                                                  ->where('is_active', true)
                                                  ->findAll();

        if (empty($webhooks)) {
            return;
        }

        $client = \Config\Services::curlrequest();

        foreach ($webhooks as $webhook) {
            $this->sendWebhook($client, $webhook, $payload);
        }
    }

    /**
     * Sends a single webhook request
     */
    protected function sendWebhook($client, $webhook, array $payload)
    {
        $payloadJson = json_encode($payload);
        $timestamp = time();
        
        // Prepare Signature (HMAC SHA256)
        // Sign: timestamp.payload using secret
        $signature = hash_hmac('sha256', "{$timestamp}.{$payloadJson}", $webhook->secret);

        try {
            $response = $client->request('POST', $webhook->target_url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Webhook-Event' => $webhook->event,
                    'X-Webhook-Timestamp' => $timestamp,
                    'X-Webhook-Signature' => $signature,
                    'User-Agent' => 'Habitaweb-Webhook/1.0'
                ],
                'body' => $payloadJson,
                'timeout' => 5,
                'http_errors' => false // Don't throw exception on 4xx/5xx
            ]);

            $statusCode = $response->getStatusCode();
            $success = $statusCode >= 200 && $statusCode < 300;
            
            // Log attempt
            $this->webhookLogModel->insert([
                'event_type' => 'dispatch.' . $webhook->event,
                'event_id' => $webhook->id, // Linking to the webhook definition ID
                'payload' => json_encode([
                    'target_url' => $webhook->target_url,
                    'request_payload' => $payload,
                    'response_code' => $statusCode,
                    'response_body' => substr($response->getBody(), 0, 1000) // Truncate if too long
                ]),
                'processed' => $success,
                'error_message' => $success ? 'OK' : "HTTP $statusCode",
                'created_at' => date('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            // Log failure
            $this->webhookLogModel->insert([
                'event_type' => 'dispatch.' . $webhook->event,
                'event_id' => $webhook->id,
                'payload' => json_encode([
                    'target_url' => $webhook->target_url,
                    'request_payload' => $payload,
                ]),
                'processed' => false,
                'error_message' => 'Exception: ' . $e->getMessage(),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    /**
     * Process a normalized webhook event
     * 
     * @param string $gateway Code of the gateway (asaas, stripe, etc)
     * @param array $normalizedEvent ['event_type' => string, 'reference_id' => string, 'status' => string, 'data' => array]
     * @return bool
     */
    public function processEvent(string $gateway, array $normalizedEvent)
    {
        $eventType = $normalizedEvent['event_type'];
        $referenceId = $normalizedEvent['reference_id']; // Gateway's Transaction/Subscription ID
        $data = $normalizedEvent['data'];

        switch ($eventType) {
            case 'PAYMENT_RECEIVED':
            case 'PAYMENT_CONFIRMED':
                return $this->handlePaymentConfirmed($gateway, $referenceId, $data);

            case 'PAYMENT_OVERDUE':
                return $this->handlePaymentOverdue($gateway, $referenceId, $data);

            case 'PAYMENT_FAILED':
            case 'PAYMENT_CANCELLED':
            case 'SUBSCRIPTION_DELETED':
                return $this->handleCancellation($gateway, $referenceId);

            case 'PAYMENT_CREATED':
                return $this->handlePaymentCreated($gateway, $referenceId, $normalizedEvent['status'] ?? 'PENDING', $data);

            case 'SUBSCRIPTION_UPDATED':
                return $this->handleSubscriptionUpdated($gateway, $referenceId, $normalizedEvent['status'] ?? null, $data);

            default:
                log_message('info', "WebhookService: Event type $eventType from $gateway not handled.");
                return true;
        }
    }

    /**
     * Handle payment confirmation
     */
    protected function handlePaymentConfirmed(string $gateway, string $gatewayId, array $data)
    {
        $transaction = $this->findTransactionByGatewayId($gateway, $gatewayId);
        $gatewaySubscriptionId = $data['subscription'] ?? null;

        if (!$transaction) {
            // Check for subscription ID if it's a subscription-based payment
            // referenceId might be a subscription_id in some events
            $transaction = $this->findTransactionBySubscriptionId($gateway, $gatewaySubscriptionId ?: $gatewayId);
        }

        if (!$transaction) {
            log_message('warning', "WebhookService: Transaction not found for $gateway ID: $gatewayId");
            return false;
        }

        $accountId = $transaction['account_id'] ?? null;
        $subscriptionId = $transaction['subscription_id'] ?? null;

        $cardProfile = null;

        // 2. Handle Tokenization (if applicable)
        if (($transaction['type'] ?? null) === 'TOKENIZATION_CHARGE' && $accountId) {
            $cardProfile = $this->processCardToken($gateway, $accountId, $data);
        }

        // 3. Update Transaction
        $this->transactionModel->update($transaction['id'], [
            'status' => 'CONFIRMED',
            'paid_at' => date('Y-m-d H:i:s')
        ]);

        // 4. Update Subscription
        if ($subscriptionId) {
            $nextBillingDate = $this->calculateNextBillingDate((int) $subscriptionId, $data);
            $this->subscriptionModel->update($subscriptionId, [
                'status' => 'ACTIVE',
                'data_inicio' => date('Y-m-d'),
                'data_fim' => $nextBillingDate,
                'next_billing_date' => $nextBillingDate
            ]);
        }

        if (
            $gateway === 'asaas'
            && ($transaction['type'] ?? null) === 'TOKENIZATION_CHARGE'
            && $subscriptionId
            && !empty($cardProfile['token'])
        ) {
            try {
                $paymentService = new PaymentService();
                $paymentService->setGateway('asaas');
                $paymentService->createNativeCreditCardSubscriptionFromTokenization(
                    (int) $subscriptionId,
                    (string) $cardProfile['token'],
                    $data,
                    $transaction
                );
            } catch (\Throwable $e) {
                log_message('error', 'WebhookService: Falha ao criar recorrencia Asaas de cartao: ' . $e->getMessage());
            }
        }

        // 5. Activate Account
        if ($accountId) {
            $this->accountModel->update($accountId, ['status' => 'ACTIVE']);
        }

        // 6. Handle Promotions (Turbo) if tagged
        if (isset($transaction['metadata'])) {
            $meta = is_string($transaction['metadata']) ? json_decode($transaction['metadata'], true) : (array) $transaction['metadata'];
            $promoKey = $meta['promo_key'] ?? $meta['package_key'] ?? null;
            if ($promoKey && isset($meta['property_id'])) {
                $promotionService = service('promotionService'); // Assuming this service exists as per AsaasWebhook
                if ($promotionService) {
                    $promotionService->activatePaidPromotion($meta['property_id'], $promoKey);
                }
            }
        }

        return true;
    }

    /**
     * Handle credit card token storage
     */
    protected function processCardToken(string $gateway, int $accountId, array $data): ?array
    {
        $token = null;
        $brand = 'UNKNOWN';
        $lastDigits = '0000';

        if ($gateway === 'asaas') {
            $card = $data['creditCard'] ?? [];
            $token = $card['creditCardToken']
                ?? $data['creditCardToken']
                ?? $card['token']
                ?? null;
            $brand = $card['creditCardBrand'] ?? 'UNKNOWN';
            $lastDigits = $this->normalizeCardLastDigits($card['creditCardNumber'] ?? $card['lastDigits'] ?? '0000');
        } elseif ($gateway === 'stripe') {
            // For Stripe, the token might be in the PaymentMethod or Subscription object
            // This depends on how it was normalized
            $token = $data['payment_method_id'] ?? $data['default_payment_method'] ?? null;
            // Additional card info parsing would go here or be provided in normalized data
        }

        if ($token) {
            // Deactivate old profiles
            $this->profileModel->where('account_id', $accountId)->set(['status' => 'INACTIVE'])->update();

            // Insert new profile
            $this->profileModel->insert([
                'account_id' => $accountId,
                'gateway' => strtoupper($gateway),
                'external_token' => $token,
                'last_digits' => $lastDigits,
                'brand' => $brand,
                'status' => 'ACTIVE'
            ]);

            return [
                'token' => $token,
                'brand' => $brand,
                'last_digits' => $lastDigits,
            ];
        }

        log_message('warning', "WebhookService: Token de cartao nao encontrado para gateway {$gateway} na conta {$accountId}.");
        return null;
    }

    protected function normalizeCardLastDigits(string $cardNumber): string
    {
        $digits = preg_replace('/\D+/', '', $cardNumber) ?: '';

        if ($digits === '') {
            return '0000';
        }

        return substr($digits, -4);
    }

    protected function calculateNextBillingDate(int $subscriptionId, array $data): string
    {
        $subscription = $this->subscriptionModel->find($subscriptionId);
        $cycle = strtoupper((string) ($subscription->billing_cycle ?? 'MONTHLY'));
        $monthsToAdd = match ($cycle) {
            'QUARTERLY' => 3,
            'SEMIANNUALLY' => 6,
            'YEARLY' => 12,
            default => 1,
        };

        foreach ([
            $data['nextDueDate'] ?? null,
            $subscription->next_billing_date ?? null,
            $subscription->data_fim ?? null,
        ] as $candidate) {
            $date = $this->parseDate($candidate);
            if ($date && $date >= new \DateTimeImmutable('today')) {
                return $date->format('Y-m-d');
            }
        }

        $baseDate = $this->parseDate($data['clientPaymentDate'] ?? null)
            ?? $this->parseDate($data['confirmedDate'] ?? null)
            ?? $this->parseDate($data['paymentDate'] ?? null)
            ?? $this->parseDate($data['dueDate'] ?? null)
            ?? new \DateTimeImmutable('today');

        return $baseDate->modify('+' . $monthsToAdd . ' months')->format('Y-m-d');
    }

    protected function parseDate($value): ?\DateTimeImmutable
    {
        if (empty($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Handle payment overdue
     */
    protected function handlePaymentOverdue(string $gateway, string $referenceId, array $data = [])
    {
        $handled = false;

        $transaction = $this->findTransactionByGatewayId($gateway, $referenceId);

        if ($transaction) {
            $this->transactionModel->update($transaction['id'], ['status' => 'OVERDUE']);
            $handled = true;
        }

        $asaasSubscriptionId = $data['subscription'] ?? $referenceId;
        $subscription = $this->subscriptionModel->where('asaas_subscription_id', $asaasSubscriptionId)->first();
        
        if ($subscription) {
            $this->subscriptionModel->update($subscription->id, ['status' => 'OVERDUE']);
            $this->accountModel->update($subscription->account_id, ['status' => 'SUSPENDED']);
            $handled = true;
        }

        return $handled;
    }

    /**
     * Handle cancellation or deletion
     */
    /**
     * Handle cancellation or deletion
     */
    protected function handleCancellation(string $gateway, string $referenceId)
    {
        // 1. Try to find a specific TRANSACTION first (Payment Deleted)
        $transaction = $this->findTransactionByGatewayId($gateway, $referenceId);

        if ($transaction) {
            $this->transactionModel->update($transaction['id'], ['status' => 'CANCELLED']);
            log_message('info', "WebhookService: Transaction {$transaction['id']} cancelled via webhook.");
            return true;
        }

        // 2. If not a transaction, try SUBSCRIPTION (Subscription Deleted)
        $subscription = $this->subscriptionModel->where('asaas_subscription_id', $referenceId)->first();

        if ($subscription) {
            $this->subscriptionModel->update($subscription->id, ['status' => 'CANCELLED']);
            $this->accountModel->update($subscription->account_id, ['status' => 'INACTIVE']);
            log_message('info', "WebhookService: Subscription {$subscription->id} cancelled via webhook.");
            return true;
        }

        return false;
    }

    /**
     * Handle subscription updates (status change, date change)
     */
    protected function handleSubscriptionUpdated(string $gateway, string $referenceId, ?string $status, array $data)
    {
        $subscription = $this->subscriptionModel->where('asaas_subscription_id', $referenceId)->first();

        if (!$subscription) {
            return false;
        }

        $updateData = [];
        if ($status) {
            $updateData['status'] = $status;
        }

        // Gateway specific date mapping
        if ($gateway === 'asaas' && isset($data['nextDueDate'])) {
            $updateData['next_billing_date'] = $data['nextDueDate'];
        }

        if (!empty($updateData)) {
            $this->subscriptionModel->update($subscription->id, $updateData);
        }

        return true;
    }

    /**
     * Handle new payment created (Invoice generated)
     */
    protected function handlePaymentCreated(string $gateway, string $gatewayId, string $status, array $data)
    {
        // 1. Check if transaction already exists
        $exists = $this->findTransactionByGatewayId($gateway, $gatewayId);

        if ($exists) {
            return true; // Already processed
        }

        // 2. Resolve Subscription
        $subscriptionId = $data["subscription"] ?? null;
        if (!$subscriptionId) {
            return true; // Not a subscription charge, ignore
        }

        // 3. Find Local Subscription
        // Note: For Asaas, $subscriptionId is the "sub_xxx" token
        $subscription = $this->subscriptionModel->where("asaas_subscription_id", $subscriptionId)->first();
        
        if (!$subscription) {
            log_message("warning", "WebhookService: Subscription not found for new charge: $subscriptionId");
            return true;
        }

        // 4. Create Pending Transaction
        $this->transactionModel->insert($this->filterTransactionData([
            "account_id" => $subscription->account_id,
            "subscription_id" => $subscription->id,
            "gateway_transaction_id" => $gatewayId,
            "gateway" => $gateway,
            "gateway_customer_id" => $data["customer"] ?? null,
            "amount" => $data["value"] ?? 0.00,
            "due_date" => $data["dueDate"] ?? null,
            "status" => "PENDING", // Always start as pending
            "type" => "RECURRING_CHARGE",
            "payment_method" => $data["billingType"] ?? "UNKNOWN",
            "invoice_url" => $data["invoiceUrl"] ?? $data["bankSlipUrl"] ?? null,
            "description" => $data["description"] ?? "Renovação de Assinatura",
            "metadata" => json_encode([
                "invoice_url" => $data["invoiceUrl"] ?? null,
                "subscription_cycle" => true
            ])
        ]));

        log_message("info", "WebhookService: New recurring charge created for Account {$subscription->account_id} (TRX: $gatewayId)");
        return true;
    }

    protected function findTransactionByGatewayId(string $gateway, string $gatewayId): ?array
    {
        foreach (['gateway_transaction_id', 'external_id'] as $idColumn) {
            if (!$this->paymentTransactionFieldExists($idColumn)) {
                continue;
            }

            $query = $this->transactionModel->where($idColumn, $gatewayId);
            $this->applyGatewayScope($query, $gateway);
            $transaction = $query->first();

            if ($transaction) {
                return $transaction;
            }
        }

        return null;
    }

    protected function findTransactionBySubscriptionId(string $gateway, ?string $subscriptionId): ?array
    {
        if (!$subscriptionId || !$this->paymentTransactionFieldExists('gateway_subscription_id')) {
            return null;
        }

        $query = $this->transactionModel
            ->where('gateway_subscription_id', $subscriptionId)
            ->orderBy('id', 'DESC');
        $this->applyGatewayScope($query, $gateway);

        return $query->first() ?: null;
    }

    protected function applyGatewayScope($query, string $gateway): void
    {
        $gatewayColumn = null;

        if ($this->paymentTransactionFieldExists('gateway')) {
            $gatewayColumn = 'gateway';
        } elseif ($this->paymentTransactionFieldExists('gateway_code')) {
            $gatewayColumn = 'gateway_code';
        }

        if (!$gatewayColumn) {
            return;
        }

        $query->whereIn($gatewayColumn, array_values(array_unique([
            $gateway,
            strtoupper($gateway),
            ucfirst(strtolower($gateway)),
        ])));
    }

    protected function filterTransactionData(array $data): array
    {
        return array_filter(
            $data,
            fn ($value, string $field): bool => $this->paymentTransactionFieldExists($field),
            ARRAY_FILTER_USE_BOTH
        );
    }

    protected function paymentTransactionFieldExists(string $field): bool
    {
        static $fields = null;

        if ($fields === null) {
            $db = \Config\Database::connect();
            $fields = $db->getFieldNames('payment_transactions');
        }

        return in_array($field, $fields, true);
    }
}
