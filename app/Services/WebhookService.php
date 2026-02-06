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

    public function __construct()
    {
        $this->accountModel = model(AccountModel::class);
        $this->subscriptionModel = model(SubscriptionModel::class);
        $this->transactionModel = model(PaymentTransactionModel::class);
        $this->profileModel = model(PaymentProfileModel::class);
        $this->webhookLogModel = model(WebhookLogModel::class);
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
                return $this->handlePaymentOverdue($gateway, $referenceId);

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
        // 1. Try to find transaction by gateway_transaction_id
        $transaction = $this->transactionModel->where([
            'gateway' => $gateway,
            'gateway_transaction_id' => $gatewayId
        ])->first();

        if (!$transaction) {
            // Check for subscription ID if it's a subscription-based payment
            // referenceId might be a subscription_id in some events
            $transaction = $this->transactionModel->where([
                'gateway' => $gateway,
                'gateway_subscription_id' => $gatewayId
            ])->orderBy('id', 'DESC')->first();
        }

        if (!$transaction) {
            log_message('warning', "WebhookService: Transaction not found for $gateway ID: $gatewayId");
            return false;
        }

        $accountId = $transaction['account_id'];
        $subscriptionId = $transaction['subscription_id'];

        // 2. Handle Tokenization (if applicable)
        if ($transaction['type'] === 'TOKENIZATION_CHARGE') {
            $this->processCardToken($gateway, $accountId, $data);
        }

        // 3. Update Transaction
        $this->transactionModel->update($transaction['id'], [
            'status' => 'CONFIRMED',
            'paid_at' => date('Y-m-d H:i:s')
        ]);

        // 4. Update Subscription
        if ($subscriptionId) {
            $this->subscriptionModel->update($subscriptionId, [
                'status' => 'ACTIVE',
                'data_inicio' => date('Y-m-d'),
                'next_billing_date' => date('Y-m-d', strtotime('+30 days'))
            ]);
        }

        // 5. Activate Account
        if ($accountId) {
            $this->accountModel->update($accountId, ['status' => 'ACTIVE']);
        }

        // 6. Handle Promotions (Turbo) if tagged
        if (isset($transaction['metadata'])) {
            $meta = is_string($transaction['metadata']) ? json_decode($transaction['metadata'], true) : (array) $transaction['metadata'];
            if (isset($meta['promo_key']) && isset($meta['property_id'])) {
                $promotionService = service('promotionService'); // Assuming this service exists as per AsaasWebhook
                if ($promotionService) {
                    $promotionService->activatePaidPromotion($meta['property_id'], $meta['promo_key']);
                }
            }
        }

        return true;
    }

    /**
     * Handle credit card token storage
     */
    protected function processCardToken(string $gateway, int $accountId, array $data)
    {
        $token = null;
        $brand = 'UNKNOWN';
        $lastDigits = '0000';

        if ($gateway === 'asaas') {
            $card = $data['creditCard'] ?? [];
            $token = $card['creditCardToken'] ?? null;
            $brand = $card['creditCardBrand'] ?? 'UNKNOWN';
            $lastDigits = $card['creditCardNumber'] ?? '0000';
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
        }
    }

    /**
     * Handle payment overdue
     */
    protected function handlePaymentOverdue(string $gateway, string $referenceId)
    {
        // Try to identify subscription/account
        $subscription = $this->subscriptionModel->where('asaas_subscription_id', $referenceId)->first();
        
        if ($subscription) {
            $this->subscriptionModel->update($subscription->id, ['status' => 'OVERDUE']);
            $this->accountModel->update($subscription->account_id, ['status' => 'SUSPENDED']);
            return true;
        }

        return false;
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
        $transaction = $this->transactionModel->where([
            'gateway' => $gateway,
            'gateway_transaction_id' => $referenceId
        ])->first();

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
            log_message('info', "WebhookService: Subscription {$subscription['id']} cancelled via webhook.");
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
        $exists = $this->transactionModel->where([
            "gateway" => $gateway,
            "gateway_transaction_id" => $gatewayId
        ])->first();

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
        $this->transactionModel->insert([
            "account_id" => $subscription->account_id,
            "subscription_id" => $subscription->id,
            "gateway_transaction_id" => $gatewayId,
            "gateway" => $gateway,
            "gateway_customer_id" => $data["customer"] ?? null,
            "amount" => $data["value"] ?? 0.00,
            "status" => "PENDING", // Always start as pending
            "type" => "RECURRING_CHARGE",
            "payment_method" => $data["billingType"] ?? "UNKNOWN",
            "invoice_url" => $data["invoiceUrl"] ?? $data["bankSlipUrl"] ?? null,
            "description" => $data["description"] ?? "Renovação de Assinatura",
            "due_date" => $data["dueDate"] ?? null,
            "metadata" => json_encode([
                "invoice_url" => $data["invoiceUrl"] ?? null,
                "subscription_cycle" => true
            ])
        ]);

        log_message("info", "WebhookService: New recurring charge created for Account {$subscription->account_id} (TRX: $gatewayId)");
        return true;
    }
}
