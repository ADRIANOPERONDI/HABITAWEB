<?php

namespace App\Controllers\Webhook;

use App\Controllers\BaseController;

class AsaasWebhookController extends BaseController
{
    protected $webhookLogModel;
    protected $subscriptionModel;
    protected $accountModel;
    protected $webhookSecret;

    public function __construct()
    {
        $this->webhookLogModel = model('App\Models\WebhookLogModel');
        $this->subscriptionModel = model('App\Models\SubscriptionModel');
        $this->accountModel = model('App\Models\AccountModel');
        
        // Load dynamic secret via Service
        $asaasService = new \App\Services\AsaasService();
        $this->webhookSecret = $asaasService->getWebhookSecret();
    }

    /**
     * Receber webhook do Asaas
     */
    public function receive()
    {
        // Pegar payload bruto
        $rawPayload = file_get_contents('php://input');
        $payload = json_decode($rawPayload, true);

        // Validar assinatura (se configurado)
        if ($this->webhookSecret) {
            $signature = $this->request->getHeaderLine('X-Asaas-Signature');
            if (!$this->validateSignature($rawPayload, $signature)) {
                log_message('error', 'Webhook Asaas: Assinatura inválida');
                return $this->response->setStatusCode(401)->setJSON(['error' => 'Invalid signature']);
            }
        }

        if (!$payload || !isset($payload['event'])) {
            return $this->response->setStatusCode(400)->setJSON(['error' => 'Invalid payload']);
        }

        $event = $payload['event'];
        $eventId = $payload['id'] ?? null;

        // Registrar webhook
        $logId = $this->webhookLogModel->logWebhook($event, $eventId, $payload);

        // Processar evento
        try {
            $this->processEvent($event, $payload);
            $this->webhookLogModel->markAsProcessed($logId);
        } catch (\Exception $e) {
            log_message('error', 'Erro ao processar webhook: ' . $e->getMessage());
            $this->webhookLogModel->markAsProcessed($logId, $e->getMessage());
        }

        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Validar assinatura HMAC
     */
    protected function validateSignature($payload, $signature)
    {
        if (empty($signature)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Processar evento recebido
     */
    protected function processEvent($event, $payload)
    {
        switch ($event) {
            case 'PAYMENT_RECEIVED':
            case 'PAYMENT_CONFIRMED':
                $this->handlePaymentConfirmed($payload);
                break;

            case 'PAYMENT_CREATED':
                $this->handlePaymentCreated($payload);
                break;

            case 'PAYMENT_OVERDUE':
                $this->handlePaymentOverdue($payload);
                break;

            case 'PAYMENT_DELETED':
            case 'PAYMENT_REFUNDED':
                $this->handlePaymentCancelled($payload);
                break;

            case 'SUBSCRIPTION_UPDATED':
                $this->handleSubscriptionUpdated($payload);
                break;

            case 'SUBSCRIPTION_DELETED':
                $this->handleSubscriptionDeleted($payload);
                break;

            default:
                log_message('info', 'Evento webhook não tratado: ' . $event);
        }
    }

    /**
     * Pagamento confirmado - Ativar subscription ou Tokenização
     */
    protected function handlePaymentConfirmed($payload)
    {
        $payment = $payload['payment'] ?? $payload;
        $paymentId = $payment['id'];
        $subscriptionId = $payment['subscription'] ?? null;
        $externalReference = $payment['externalReference'] ?? null;
        $creditCardToken = $payment['creditCard']['creditCardToken'] ?? null;

        // 1. Verificar se é um pagamento de PROMOÇÃO
        if ($externalReference && strpos($externalReference, 'PROMO_') === 0) {
            $this->handlePromotionPaymentConfirmed($payment);
            return;
        }

        // 2. Verificar se existe Transação Local (Prioridade para fluxo novo/tokenização)
        $transactionModel = model('App\Models\PaymentTransactionModel');
        $localTransaction = $transactionModel->where('external_id', $paymentId)->first();

        if ($localTransaction && $localTransaction['type'] === 'TOKENIZATION_CHARGE') {
            $this->handleTokenizationChargeConfirmed($localTransaction, $payment);
            return;
        }

        // 3. Fallback: Verificar se é uma ASSINATURA NATIVA (Legado ou fluxo direto)
        if ($subscriptionId) {
            // Buscar subscription local pelo ID do Asaas
            $subscription = $this->subscriptionModel
                ->where('asaas_subscription_id', $subscriptionId)
                ->first();

            if (!$subscription) {
                log_message('warning', 'Subscription não encontrada: ' . $subscriptionId);
                return;
            }

            // Ativar subscription
            $this->subscriptionModel->update($subscription->id, [
                'status' => 'ACTIVE',
                'next_billing_date' => date('Y-m-d', strtotime('+30 days'))
            ]);

            // Ativar conta
            if ($subscription->account_id) {
                $this->accountModel->update($subscription->account_id, [
                    'status' => 'ACTIVE'
                ]);
            }

            log_message('info', 'Subscription ativada: ' . $subscription->id);
        }
    }

    /**
     * Processar confirmação de pagamento de Tokenização (Primeira Cobrança)
     */
    protected function handleTokenizationChargeConfirmed($transaction, $paymentPayload)
    {
        $accountId = $transaction['account_id'];
        $creditCard = $paymentPayload['creditCard'] ?? [];
        $creditCardToken = $creditCard['creditCardToken'] ?? null;
        
        // 1. Salvar Token se existir
        if ($creditCardToken) {
            $profileModel = model('App\Models\PaymentProfileModel');
            
            // Invalidar anteriores
            $profileModel->where('account_id', $accountId)->set(['status' => 'INACTIVE'])->update();
            
            // Salvar novo
            $profileModel->insert([
                'account_id' => $accountId,
                'gateway' => 'ASAAS',
                'external_token' => $creditCardToken,
                'last_digits' => $creditCard['creditCardNumber'] ?? '0000',
                'brand' => $creditCard['creditCardBrand'] ?? 'UNKNOWN',
                'status' => 'ACTIVE'
            ]);
            
            log_message('info', "Token cartão salvo para conta $accountId: $creditCardToken");
        }

        // 2. Ativar Assinatura Local PENDENTE
        $localSubId = $transaction['reference_id'];
        if ($localSubId) {
            $this->subscriptionModel->update($localSubId, [
                'status' => 'ACTIVE',
                'data_inicio' => date('Y-m-d'),
                'next_billing_date' => date('Y-m-d', strtotime('+30 days')),
                // Importante: Não setamos asaas_subscription_id pois é recorrência manual
            ]);
            log_message('info', "Assinatura local $localSubId ativada via Tokenização.");
        }

        // 3. Confirmar Transação
        $transactionModel = model('App\Models\PaymentTransactionModel');
        $transactionModel->update($transaction['id'], ['status' => 'CONFIRMED']);

        // 4. Ativar Conta
        if ($accountId) {
            $this->accountModel->update($accountId, ['status' => 'ACTIVE']);
        }
    }

    /**
     * Pagamento atrasado - Suspender conta
     */
    protected function handlePaymentOverdue($payload)
    {
        $payment = $payload['payment'] ?? $payload;
        $subscriptionId = $payment['subscription'] ?? null;

        if (!$subscriptionId) {
            return;
        }

        $subscription = $this->subscriptionModel
            ->where('asaas_subscription_id', $subscriptionId)
            ->first();

        if (!$subscription) {
            return;
        }

        // Suspender conta
        if ($subscription->account_id) {
            $this->accountModel->update($subscription->account_id, [
                'status' => 'SUSPENDED'
            ]);
        }

        // Atualizar status da subscription
        $this->subscriptionModel->update($subscription->id, [
            'status' => 'OVERDUE'
        ]);

        log_message('info', 'Conta suspensa por atraso: ' . $subscription->account_id);
    }

    /**
     * Pagamento cancelado/reembolsado
     */
    protected function handlePaymentCancelled($payload)
    {
        $payment = $payload['payment'] ?? $payload;
        $subscriptionId = $payment['subscription'] ?? null;

        if (!$subscriptionId) {
            return;
        }

        $subscription = $this->subscriptionModel
            ->where('asaas_subscription_id', $subscriptionId)
            ->first();

        if (!$subscription) {
            return;
        }

        $this->subscriptionModel->update($subscription->id, [
            'status' => 'CANCELLED'
        ]);

        log_message('info', 'Subscription cancelada: ' . $subscription->id);
    }

    /**
     * Subscription atualizada
     */
    protected function handleSubscriptionUpdated($payload)
    {
        $asaasSubscription = $payload['subscription'] ?? $payload;
        
        $subscription = $this->subscriptionModel
            ->where('asaas_subscription_id', $asaasSubscription['id'])
            ->first();

        if (!$subscription) {
            return;
        }

        // Atualizar dados locais
        $updateData = [];
        
        if (isset($asaasSubscription['status'])) {
            $updateData['status'] = $asaasSubscription['status'];
        }
        
        if (isset($asaasSubscription['nextDueDate'])) {
            $updateData['next_billing_date'] = $asaasSubscription['nextDueDate'];
        }

        if (!empty($updateData)) {
            $this->subscriptionModel->update($subscription->id, $updateData);
        }

        log_message('info', 'Subscription atualizada: ' . $subscription->id);
    }

    /**
     * Subscription deletada
     */
    protected function handleSubscriptionDeleted($payload)
    {
        $asaasSubscription = $payload['subscription'] ?? $payload;
        
        $subscription = $this->subscriptionModel
            ->where('asaas_subscription_id', $asaasSubscription['id'])
            ->first();

        if (!$subscription) {
            return;
        }

        // Desativar subscription e conta
        $this->subscriptionModel->update($subscription->id, [
            'status' => 'INACTIVE'
        ]);

        if ($subscription->account_id) {
            $this->accountModel->update($subscription->account_id, [
                'status' => 'INACTIVE'
            ]);
        }

        log_message('info', 'Subscription desativada: ' . $subscription->id);
    }

    /**
     * Tratar confirmação de pagamento de uma promoção (Turbo)
     */
    protected function handlePromotionPaymentConfirmed($payment)
    {
        $transactionId = $payment['id'];
        $transactionModel = model('App\Models\PaymentTransactionModel');
        
        $localTransaction = $transactionModel->where('gateway_transaction_id', $transactionId)->first();

        if (!$localTransaction) {
            log_message('warning', 'Transação de promoção não encontrada no banco local: ' . $transactionId);
            return;
        }

        if ($localTransaction['status'] === 'CONFIRMED') {
            return; // Já processado
        }

        // Marcar transação como confirmada
        $transactionModel->update($localTransaction['id'], ['status' => 'CONFIRMED']);

        // Ativar a promoção
        $metadata = json_decode($localTransaction['metadata'], true);
        if (isset($metadata['property_id']) && isset($metadata['package_key'])) {
            $promotionService = service('promotionService');
            $promotionService->activatePaidPromotion($metadata['property_id'], $metadata['package_key']);
            log_message('info', "Promoção turbinada e ativada para imóvel ID: {$metadata['property_id']}");
        }
    }

    /**
     * Pagamento Criado (Nova Fatura Gerada pelo Asaas)
     */
    protected function handlePaymentCreated($payload)
    {
        $payment = $payload['payment'] ?? $payload;
        $paymentId = $payment['id'];
        $subscriptionId = $payment['subscription'] ?? null;
        
        // Se não for cobrança de assinatura, ignora por enquanto para não gerar lixo
        if (!$subscriptionId) {
            return;
        }

        // 1. Verificar se já existe localmente
        $transactionModel = model('App\Models\PaymentTransactionModel');
        $exists = $transactionModel->where('gateway_transaction_id', $paymentId)->first();

        if ($exists) {
            return;
        }

        // 2. Buscar a assinatura local
        $subscription = $this->subscriptionModel->where('asaas_subscription_id', $subscriptionId)->first();

        if ($subscription) {
            // 3. Criar nova transação PENDING
            $transactionModel->insert([
                'account_id' => $subscription->account_id,
                'subscription_id' => $subscription->id,
                'gateway_transaction_id' => $paymentId,
                'gateway' => 'Asaas',
                'gateway_customer_id' => $payment['customer'],
                'payment_method' => $payment['billingType'],
                'amount' => $payment['value'],
                'status' => 'PENDING',
                'type' => 'RECURRING_CHARGE', // Nova cobrança recorrente
                'invoice_url' => $payment['invoiceUrl'] ?? $payment['bankSlipUrl'] ?? null,
                'description' => $payment['description'] ?? 'Assinatura Mensal',
                'due_date' => $payment['dueDate'],
                'metadata' => json_encode([
                    'invoice_url' => $payment['invoiceUrl'],
                    'subscription_cycle' => true
                ])
            ]);
            
            log_message('info', "Nova fatura recorrente criada via Webhook: $paymentId para conta {$subscription->account_id}");
        }
    }
}
// End of controller
