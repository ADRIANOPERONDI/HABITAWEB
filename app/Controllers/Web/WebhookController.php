<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\WebhookLogModel;

class WebhookController extends BaseController
{
    private const WITHDRAWAL_TYPES = [
        'TRANSFER' => 'transfer',
        'BILL' => 'bill',
        'PIX_QR_CODE' => 'pixQrCode',
        'MOBILE_PHONE_RECHARGE' => 'mobilePhoneRecharge',
        'PIX_REFUND' => 'pixRefund',
    ];

    private const PAYMENT_CANCELLED_EVENTS = [
        'PAYMENT_DELETED',
        'PAYMENT_REFUNDED',
        'PAYMENT_PARTIALLY_REFUNDED',
        'PAYMENT_BANK_SLIP_CANCELLED',
        'PAYMENT_CHARGEBACK_REQUESTED',
        'PAYMENT_CHARGEBACK_DISPUTE',
        'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED',
    ];

    private WebhookLogModel $webhookLogModel;

    public function __construct()
    {
        $this->webhookLogModel = model(WebhookLogModel::class);
    }

    /**
     * Endpoint publico para eventos gerais do Asaas.
     * URL de producao: /asaas/webhook
     */
    public function asaas()
    {
        if (!$this->hasValidAsaasToken()) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON(['received' => false, 'error' => 'Invalid Asaas token']);
        }

        $payload = $this->request->getJSON(true);

        if (!is_array($payload)) {
            $this->logInvalidPayload('asaas.invalid_payload', []);

            return $this->response->setJSON([
                'received' => true,
                'ignored' => true,
                'reason' => 'Invalid payload',
            ]);
        }

        $event = strtoupper((string) ($payload['event'] ?? ''));

        if ($event === '') {
            $this->logInvalidPayload('asaas.invalid_payload', $payload);

            return $this->response->setJSON([
                'received' => true,
                'ignored' => true,
                'reason' => 'Missing event',
            ]);
        }

        $eventType = 'asaas.' . $event;
        $eventId = $this->extractAsaasEventId($payload);
        $log = $this->logWebhookOnce($eventType, $eventId, $payload);

        if ($log['duplicate']) {
            return $this->response->setJSON([
                'received' => true,
                'duplicate' => true,
            ]);
        }

        try {
            $normalized = $this->normalizeAsaasEvent($payload);

            if ($normalized === null) {
                $this->webhookLogModel->markAsProcessed($log['id'], 'Event ignored by HabitaWeb');

                return $this->response->setJSON([
                    'received' => true,
                    'ignored' => true,
                ]);
            }

            $processed = service('webhookService')->processEvent('asaas', $normalized);
            $this->webhookLogModel->markAsProcessed(
                $log['id'],
                $processed ? null : 'No local record matched this Asaas event'
            );
        } catch (\Throwable $e) {
            log_message('error', 'Asaas webhook error: ' . $e->getMessage());
            $this->webhookLogModel->markAsProcessed($log['id'], $e->getMessage());
        }

        return $this->response->setJSON(['received' => true]);
    }

    /**
     * Endpoint para validacao de saque via webhook Asaas.
     * URL de producao: /asaas/saques/validar
     */
    public function validateWithdrawal()
    {
        if (!$this->hasValidAsaasToken()) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'status' => 'REFUSED',
                    'refuseReason' => 'Token de autenticacao invalido',
                ]);
        }

        $payload = $this->request->getJSON(true);

        if (!is_array($payload)) {
            $this->logInvalidPayload('asaas.withdrawal_validation.invalid_payload', []);

            return $this->response->setJSON([
                'status' => 'REFUSED',
                'refuseReason' => 'Payload invalido',
            ]);
        }

        $type = $this->extractWithdrawalType($payload);

        if ($type === '') {
            $this->logWithdrawalDecision('UNKNOWN', 'REFUSED', $payload, 'Tipo da solicitacao nao informado');

            return $this->response->setJSON([
                'status' => 'REFUSED',
                'refuseReason' => 'Tipo da solicitacao nao informado',
            ]);
        }

        if (!array_key_exists($type, self::WITHDRAWAL_TYPES)) {
            $this->logWithdrawalDecision($type, 'REFUSED', $payload, 'Tipo da solicitacao nao suportado');

            return $this->response->setJSON([
                'status' => 'REFUSED',
                'refuseReason' => 'Tipo da solicitacao nao suportado',
            ]);
        }

        $this->logWithdrawalDecision($type, 'APPROVED', $payload);

        return $this->response->setJSON(['status' => 'APPROVED']);
    }

    private function hasValidAsaasToken(): bool
    {
        $expected = $this->getAsaasWebhookToken();
        $provided = trim((string) $this->request->getHeaderLine('asaas-access-token'));

        return $expected !== ''
            && $provided !== ''
            && hash_equals($expected, $provided);
    }

    private function getAsaasWebhookToken(): string
    {
        $config = config('Asaas');
        $token = $config->webhookToken
            ?: $config->webhookSecret
            ?: env('ASAAS_WEBHOOK_TOKEN', env('ASAAS_WEBHOOK_SECRET', ''));

        return trim((string) $token);
    }

    private function normalizeAsaasEvent(array $payload): ?array
    {
        $event = strtoupper((string) ($payload['event'] ?? ''));

        if (strpos($event, 'PAYMENT_') === 0) {
            return $this->normalizeAsaasPaymentEvent($event, $payload);
        }

        if (strpos($event, 'SUBSCRIPTION_') === 0) {
            return $this->normalizeAsaasSubscriptionEvent($event, $payload);
        }

        return [
            'event_type' => $event,
            'reference_id' => $this->extractAsaasEventId($payload),
            'status' => $payload['status'] ?? null,
            'data' => $payload,
        ];
    }

    private function normalizeAsaasPaymentEvent(string $event, array $payload): ?array
    {
        $payment = $payload['payment'] ?? null;

        if (!is_array($payment) || empty($payment['id'])) {
            return null;
        }

        $eventType = $event;

        if (in_array($event, self::PAYMENT_CANCELLED_EVENTS, true)) {
            $eventType = 'PAYMENT_CANCELLED';
        }

        return [
            'event_type' => $eventType,
            'reference_id' => (string) $payment['id'],
            'status' => $payment['status'] ?? null,
            'data' => $payment,
        ];
    }

    private function normalizeAsaasSubscriptionEvent(string $event, array $payload): ?array
    {
        $subscription = $payload['subscription'] ?? null;

        if (!is_array($subscription) || empty($subscription['id'])) {
            return null;
        }

        return [
            'event_type' => $event === 'SUBSCRIPTION_DELETED' ? 'SUBSCRIPTION_DELETED' : 'SUBSCRIPTION_UPDATED',
            'reference_id' => (string) $subscription['id'],
            'status' => $subscription['status'] ?? null,
            'data' => $subscription,
        ];
    }

    private function extractAsaasEventId(array $payload): string
    {
        $resource = $payload['payment'] ?? $payload['subscription'] ?? null;

        if (!empty($payload['id'])) {
            return (string) $payload['id'];
        }

        if (is_array($resource) && !empty($resource['id'])) {
            return (string) $resource['id'];
        }

        return sha1(json_encode($payload) ?: serialize($payload));
    }

    private function extractWithdrawalType(array $payload): string
    {
        $type = $payload['type']
            ?? $payload['operationType']
            ?? $payload['requestType']
            ?? $payload['event']
            ?? null;

        if ($type) {
            return strtoupper((string) $type);
        }

        foreach (self::WITHDRAWAL_TYPES as $knownType => $resourceKey) {
            if (isset($payload[$resourceKey])) {
                return $knownType;
            }
        }

        return '';
    }

    private function logWithdrawalDecision(string $type, string $decision, array $payload, ?string $reason = null): void
    {
        $resourceKey = self::WITHDRAWAL_TYPES[$type] ?? null;
        $resource = $resourceKey ? ($payload[$resourceKey] ?? []) : [];
        $resourceId = is_array($resource) ? ($resource['id'] ?? null) : null;
        $eventId = (string) ($payload['id'] ?? $resourceId ?? sha1(json_encode($payload) ?: serialize($payload)));
        $eventType = 'asaas.withdrawal_validation.' . $type;

        $log = $this->logWebhookOnce($eventType, $eventId, [
            'decision' => $decision,
            'reason' => $reason,
            'payload' => $payload,
        ]);

        if (!$log['duplicate']) {
            $this->webhookLogModel->markAsProcessed($log['id'], $reason);
        }
    }

    private function logInvalidPayload(string $eventType, array $payload): void
    {
        $eventId = sha1(json_encode($payload) ?: serialize($payload));
        $log = $this->logWebhookOnce($eventType, $eventId, $payload);

        if (!$log['duplicate']) {
            $this->webhookLogModel->markAsProcessed($log['id'], 'Invalid payload');
        }
    }

    private function logWebhookOnce(string $eventType, string $eventId, array $payload): array
    {
        $eventId = $eventId !== '' ? $eventId : sha1(json_encode($payload) ?: serialize($payload));
        $existing = $this->webhookLogModel
            ->select('id, processed')
            ->where('event_type', $eventType)
            ->where('event_id', $eventId)
            ->first();

        if ($existing) {
            return [
                'id' => (int) $existing['id'],
                'duplicate' => (bool) ($existing['processed'] ?? false),
            ];
        }

        return [
            'id' => (int) $this->webhookLogModel->logWebhook($eventType, $eventId, $payload),
            'duplicate' => false,
        ];
    }
}
