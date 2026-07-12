<?php

namespace App\PaymentGateways;

use CodeIgniter\HTTP\CURLRequest;
use Exception;

class MercadoPagoGateway implements GatewayInterface
{
    protected $config;
    protected $client;
    protected $baseUrl = 'https://api.mercadopago.com';

    public function __construct()
    {
        $this->client = \Config\Services::curlrequest();
    }

    public function getCode(): string
    {
        return 'mercadopago';
    }

    public function getName(): string
    {
        return 'Mercado Pago';
    }

    public function configure(array $config): void
    {
        $this->config = $config;
    }

    public function validateConfig(): bool
    {
        return !empty($this->config['access_token']) && !empty($this->config['public_key']);
    }

    public function createCustomer(array $customerData): string
    {
        // 1. Search existing customer
        $existing = $this->searchCustomer($customerData['email']);
        if ($existing) {
            return $existing['id'];
        }

        // 2. Create new customer
        $payload = [
            'email' => $customerData['email'],
            'first_name' => $customerData['name'],
            'phone' => [
                'area_code' => substr($customerData['phone'], 0, 2),
                'number' => substr($customerData['phone'], 2)
            ],
            'identification' => [
                'type' => 'CPF',
                'number' => $customerData['document'] ?? ''
            ]
        ];

        $customer = $this->request('POST', '/v1/customers', $payload);
        return $customer['id'];
    }

    public function createSubscription(string $customerId, string $planId, array $data): array
    {
        // Mercado Pago Subscriptions use /preapproval
        // Needs card_token_id

        $payload = [
            'payer_email' => $data['email'] ?? '',
            'back_url' => base_url('payment/mp/return'),
            'reason' => $data['description'] ?? 'Assinatura',
            'external_reference' => $data['external_reference'] ?? '',
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => (float)$data['amount'],
                'currency_id' => 'BRL'
            ],
            'status' => 'authorized'
        ];

        // Se tiver token de cartão, enviamos (obrigatório para auto-recurring sem redirect)
        if (!empty($data['cardToken'])) {
            $payload['card_token_id'] = $data['cardToken'];
        }

        $sub = $this->request('POST', '/preapproval', $payload);

        return [
            'subscription_id' => $sub['id'],
            'status' => $this->mapStatus($sub['status']),
            'next_billing_date' => $sub['next_payment_date'] ?? date('Y-m-d', strtotime('+1 month'))
        ];
    }

    public function createPayment(string $customerId, float $amount, array $data): array
    {
        $payload = [
            'transaction_amount' => (float)$amount,
            'description' => $data['description'] ?? '',
            'payment_method_id' => 'credit_card', // Simplificado
            'payer' => [
                'email' => $data['email'] ?? '',
                'id' => $customerId
            ],
            'external_reference' => $data['external_reference'] ?? ''
        ];

        if (!empty($data['cardToken'])) {
            $payload['token'] = $data['cardToken'];
            $payload['installments'] = 1;
        }

        $payment = $this->request('POST', '/v1/payments', $payload);

        return [
            'payment_id' => $payment['id'],
            'status' => $this->mapStatus($payment['status']),
            'payment_url' => $payment['point_of_interaction']['transaction_data']['ticket_url'] ?? '',
            'qr_code' => $payment['point_of_interaction']['transaction_data']['qr_code'] ?? null
        ];
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        try {
            $this->request('PUT', "/preapproval/{$subscriptionId}", ['status' => 'cancelled']);
            return true;
        } catch (Exception $e) {
            log_message('error', 'Erro ao cancelar MP Sub: ' . $e->getMessage());
            return false;
        }
    }

    public function cancelPayment(string $paymentId): bool
    {
        try {
            $this->request('PUT', "/v1/payments/{$paymentId}", ['status' => 'cancelled']);
            return true;
        } catch (Exception $e) {
            log_message('error', 'Erro ao cancelar MP Payment: ' . $e->getMessage());
            return false;
        }
    }

    public function updateSubscription(string $subscriptionId, array $data): bool
    {
        $payload = [];
        if (isset($data['amount'])) {
            $payload['auto_recurring'] = ['transaction_amount' => $data['amount']];
        }
        
        $this->request('PUT', "/preapproval/{$subscriptionId}", $payload);
        return true;
    }

    public function suspendSubscription(string $subscriptionId): bool
    {
        $this->request('PUT', "/preapproval/{$subscriptionId}", ['status' => 'paused']);
        return true;
    }

    public function reactivateSubscription(string $subscriptionId): bool
    {
        $this->request('PUT', "/preapproval/{$subscriptionId}", ['status' => 'authorized']);
        return true;
    }

    public function handleWebhook(array $payload, array $headers = [], string $rawBody = ''): array
    {
        // MP envia ?topic=payment&id=123
        // Ou body com type=payment

        $type = $payload['type'] ?? ($payload['topic'] ?? '');
        $id = $payload['data']['id'] ?? ($payload['id'] ?? '');

        // Valida a assinatura x-signature antes de confiar no evento (fail-closed).
        // A re-consulta na API abaixo é defesa adicional, não substitui a assinatura.
        $this->verifyWebhookSignature($headers, (string) $id);

        // Fetch details to confirm status (Security best practice)
        $data = [];
        if ($type === 'payment') {
            $data = $this->request('GET', "/v1/payments/{$id}");
        } elseif ($type === 'subscription' || $type === 'preapproval') {
             // ... fetch subscription
        }

        $event = [
            'event_type' => 'UNKNOWN',
            'reference_id' => $id,
            'status' => '',
            'data' => $data
        ];

        // Map status
        if (!empty($data['status'])) {
            if ($data['status'] === 'approved') {
                $event['event_type'] = 'PAYMENT_RECEIVED';
                $event['status'] = 'CONFIRMED';
            } elseif ($data['status'] === 'rejected') {
                $event['event_type'] = 'PAYMENT_FAILED';
            }
        }

        return $event;
    }

    /**
     * Verifica o cabeçalho x-signature do Mercado Pago (esquema ts=...,v1=hmac),
     * usando o manifesto id;request-id;ts conforme a documentação. Fail-closed.
     */
    private function verifyWebhookSignature(array $headers, string $dataId): void
    {
        $secret     = (string) ($this->config['webhook_secret'] ?? '');
        $xSignature = (string) ($headers['x-signature'] ?? '');
        $xRequestId = (string) ($headers['x-request-id'] ?? '');

        if ($secret === '' || $xSignature === '') {
            throw new \Exception('Webhook Mercado Pago rejeitado: assinatura ausente ou secret não configurado.');
        }

        $ts = '';
        $v1 = '';
        foreach (explode(',', $xSignature) as $kv) {
            $pair = array_pad(explode('=', trim($kv), 2), 2, '');
            if ($pair[0] === 'ts') {
                $ts = $pair[1];
            } elseif ($pair[0] === 'v1') {
                $v1 = $pair[1];
            }
        }

        if ($ts === '' || $v1 === '') {
            throw new \Exception('Webhook Mercado Pago rejeitado: cabeçalho de assinatura malformado.');
        }

        if (abs(time() - (int) $ts) > 300) {
            throw new \Exception('Webhook Mercado Pago rejeitado: fora da janela de tempo (possível replay).');
        }

        $manifest = 'id:' . strtolower($dataId) . ';request-id:' . $xRequestId . ';ts:' . $ts . ';';
        $expected = hash_hmac('sha256', $manifest, $secret);

        if (! hash_equals($expected, $v1)) {
            throw new \Exception('Webhook Mercado Pago rejeitado: assinatura inválida.');
        }
    }

    /**
     * Métodos da interface ainda não implementados para o Mercado Pago. Lançam exceção
     * (fail-loud) em vez de retornar dado vazio, para não mascarar uso indevido caso o
     * gateway seja ativado sem completar a implementação.
     */
    public function findCustomerByDocument(string $document): ?string
    {
        throw new \Exception('MercadoPagoGateway::findCustomerByDocument ainda não implementado.');
    }

    public function updateCustomer(string $customerId, array $customerData): bool
    {
        throw new \Exception('MercadoPagoGateway::updateCustomer ainda não implementado.');
    }

    public function getSubscription(string $subscriptionId): ?array
    {
        throw new \Exception('MercadoPagoGateway::getSubscription ainda não implementado.');
    }

    public function getPendingPayments(string $customerId): array
    {
        throw new \Exception('MercadoPagoGateway::getPendingPayments ainda não implementado.');
    }

    public function getSupportedMethods(): array
    {
        return ['CREDIT_CARD', 'PIX', 'BOLETO'];
    }

    // Helpers

    protected function request($method, $endpoint, $data = [])
    {
        if (empty($this->config['access_token'])) {
            throw new Exception("Mercado Pago Access Token não configurado.");
        }

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config['access_token'],
                'Content-Type' => 'application/json'
            ],
            'http_errors' => false
        ];

        if (!empty($data) && $method !== 'GET') {
            $options['json'] = $data;
        }
        if (!empty($data) && $method === 'GET') {
            $options['query'] = $data;
        }

        $response = $this->client->request($method, $this->baseUrl . $endpoint, $options);
        $body = $response->getBody();
        $json = json_decode($body, true);

        if ($response->getStatusCode() >= 400) {
            $msg = $json['message'] ?? $json['error'] ?? 'Erro desconhecido Mercado Pago';
            throw new Exception($msg);
        }

        return $json;
    }

    protected function searchCustomer($email)
    {
        $res = $this->request('GET', '/v1/customers/search', ['email' => $email]);
        if (!empty($res['results'])) {
            return [
                'id' => $res['results'][0]['id'],
                'gateway' => 'mercadopago'
            ];
        }
        return null;
    }

    public function getActiveSubscription(string $customerId): ?array
    {
        // MP subscription search is complex and usually handled via preapproval search.
        // For MVP, we'll implement a simplified check or return null if not easily queryable.
        return null; 
    }

    protected function mapStatus($mpStatus)
    {
        $map = [
            'authorized' => 'ACTIVE',
            'paused' => 'SUSPENDED',
            'cancelled' => 'CANCELLED',
            'pending' => 'PENDING',
            'approved' => 'CONFIRMED',
            'rejected' => 'FAILED',
            'in_process' => 'PENDING'
        ];
        return $map[$mpStatus] ?? 'PENDING';
    }
}
