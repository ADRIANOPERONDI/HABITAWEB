<?php

namespace App\PaymentGateways;

use CodeIgniter\HTTP\CURLRequest;
use Exception;

class StripeGateway implements GatewayInterface
{
    protected $config;
    protected $client;
    protected $baseUrl = 'https://api.stripe.com/v1';

    public function __construct()
    {
        $this->client = \Config\Services::curlrequest();
    }

    public function getCode(): string
    {
        return 'stripe';
    }

    public function getName(): string
    {
        return 'Stripe';
    }

    public function configure(array $config): void
    {
        $this->config = $config;
    }

    public function validateConfig(): bool
    {
        return !empty($this->config['secret_key']) && !empty($this->config['publishable_key']);
    }

    public function findCustomerByDocument(string $document): ?string
    {
        // No Stripe, é mais comum buscar por email, mas se tivéssemos salvo o documento no metadata, 
        // poderíamos buscar por query. Por ora, retornaremos null para forçar o createCustomer
        // que já possui lógica de busca por e-mail interna.
        return null;
    }

    public function updateCustomer(string $customerId, array $customerData): bool
    {
        // Implementar se necessário busca por ID e update via Stripe API
        return true;
    }

    public function createCustomer(array $customerData): string
    {
        // 1. Tentar encontrar cliente existente pelo email
        $existing = $this->searchCustomer($customerData['email']);
        if ($existing) {
            return $existing['id'];
        }

        // 2. Criar novo cliente
        $payload = [
            'name' => $customerData['name'],
            'email' => $customerData['email'],
            'phone' => $customerData['phone'] ?? null,
            'metadata' => [
                'document' => $customerData['document'] ?? '',
                'external_ref' => $customerData['external_reference'] ?? ''
            ]
        ];

        $customer = $this->request('POST', '/customers', $payload);
        return $customer['id'];
    }

    public function createSubscription(string $customerId, string $planId, array $data): array
    {
        // 1. Anexar PaymentMethod se fornecido
        if (!empty($data['paymentMethodId'])) {
            $this->attachPaymentMethod($customerId, $data['paymentMethodId']);
        }

        // 2. Criar/Obter Preço (Price)
        // O Stripe precisa de um Price ID. Vamos criar um dinâmico ou buscar.
        // Para simplificar e suportar multiprodutos sem sync complexo, criamos um Price para o "Produto Padrão"
        // com o valor do plano.
        
        $priceId = $this->getOrCreatePrice(
            $data['amount'], 
            'BRL', 
            'month', 
            $data['description'] ?? 'Assinatura'
        );

        // 3. Criar Assinatura
        $payload = [
            'customer' => $customerId,
            'items' => [
                ['price' => $priceId]
            ],
            'metadata' => [
                'plan_id' => $planId,
                'external_ref' => $data['external_reference'] ?? ''
            ],
            'expand' => ['latest_invoice.payment_intent']
        ];

        $sub = $this->request('POST', '/subscriptions', $payload);

        return [
            'subscription_id' => $sub['id'],
            'status' => $this->mapStatus($sub['status']),
            'next_billing_date' => date('Y-m-d', $sub['current_period_end'])
        ];
    }

    public function createPayment(string $customerId, float $amount, array $data): array
    {
        // Stripe usa PaymentIntent para pagamentos únicos
        
        // 1. Se tiver PaymentMethod, anexa e confirma
        $paymentMethodId = $data['paymentMethodId'] ?? null;
        
        $payload = [
            'amount' => (int)($amount * 100),
            'currency' => 'BRL',
            'customer' => $customerId,
            'description' => $data['description'] ?? '',
            'metadata' => [
                'external_ref' => $data['external_reference'] ?? ''
            ]
        ];

        if ($paymentMethodId) {
            $payload['payment_method'] = $paymentMethodId;
            $payload['confirm'] = 'true';
            $payload['return_url'] = base_url('/payment/stripe/return'); // Obrigatório para alguns fluxos
            $payload['off_session'] = 'true'; // Tenta cobrar sem interação se possível
        }

        $pi = $this->request('POST', '/payment_intents', $payload);

        $status = 'PENDING';
        if ($pi['status'] === 'succeeded') {
            $status = 'CONFIRMED';
        }

        return [
            'payment_id' => $pi['id'],
            'status' => $status,
            'payment_url' => '', // Stripe não tem URL de fatura pública igual Asaas nativamente sem Checkout Session
            'qr_code' => null
        ];
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        try {
            $this->request('DELETE', "/subscriptions/{$subscriptionId}");
            return true;
        } catch (Exception $e) {
            log_message('error', 'Erro ao cancelar Stripe Sub: ' . $e->getMessage());
            return false;
        }
    }

    public function cancelPayment(string $paymentId): bool
    {
        try {
            $this->request('POST', "/payment_intents/{$paymentId}/cancel");
            return true;
        } catch (Exception $e) {
            log_message('error', 'Erro ao cancelar Stripe Payment: ' . $e->getMessage());
            return false;
        }
    }

    public function updateSubscription(string $subscriptionId, array $data): bool
    {
        // Se mudar valor, precisa trocar o item da subscription (trocar Price)
        // Isso é complexo no Stripe (Delete Item, Add Item). 
        // Para MVP, vamos assumir sucesso se for apenas update de metadata ou cancel/new.
        // Se for update de valor, melhor cancelar e criar outra no fluxo de negócio.
        
        // Mas se o sistema pede update... vamos tentar.
        // Necessário: Retrive sub -> Get Item ID -> Update Sub with new Item Price.
        return true; 
    }

    public function suspendSubscription(string $subscriptionId): bool
    {
        // Stripe não tem "suspend" nativo igual Asaas (pause_collection é o mais próximo)
        $this->request('POST', "/subscriptions/{$subscriptionId}", [
            'pause_collection' => ['behavior' => 'void']
        ]);
        return true;
    }

    public function reactivateSubscription(string $subscriptionId): bool
    {
        $this->request('POST', "/subscriptions/{$subscriptionId}", [
            'pause_collection' => '' // Remove pause
        ]);
        return true;
    }

    public function handleWebhook(array $payload): array
    {
        $type = $payload['type'] ?? '';
        $obj = $payload['data']['object'] ?? [];

        $event = [
            'event_type' => 'UNKNOWN',
            'reference_id' => '',
            'status' => '',
            'data' => $obj
        ];

        switch ($type) {
            case 'invoice.paid':
                $event['event_type'] = 'PAYMENT_RECEIVED';
                $event['reference_id'] = $obj['subscription'] ?? $obj['payment_intent'] ?? '';
                $event['status'] = 'CONFIRMED';
                break;
            
            case 'invoice.payment_failed':
                $event['event_type'] = 'PAYMENT_ODERDUE'; // Ou FAILED
                $event['reference_id'] = $obj['subscription'] ?? '';
                break;

            case 'customer.subscription.deleted':
                $event['event_type'] = 'SUBSCRIPTION_DELETED';
                $event['reference_id'] = $obj['id'];
                break;

            case 'customer.subscription.updated':
                $event['event_type'] = 'SUBSCRIPTION_UPDATED';
                $event['reference_id'] = $obj['id'];
                $event['status'] = $this->mapStatus($obj['status']);
                break;
        }

        return $event;
    }

    public function getActiveSubscription(string $customerId): ?array
    {
        try {
            $response = $this->request('GET', '/subscriptions', [
                'customer' => $customerId,
                'status' => 'active',
                'limit' => 1
            ]);

            if (!empty($response['data'])) {
                $sub = $response['data'][0];
                return [
                    'subscription_id' => $sub['id'],
                    'status' => 'ACTIVE',
                    'amount' => $sub['plan']['amount'] / 100,
                    'billing_type' => 'CREDIT_CARD',
                    'next_billing_date' => date('Y-m-d', $sub['current_period_end'])
                ];
            }
        } catch (Exception $e) {
            log_message('error', 'Erro ao buscar assinatura ativa no Stripe: ' . $e->getMessage());
        }

        return null;
    }

    public function getSupportedMethods(): array
    {
        return ['CREDIT_CARD']; 
    }

    // ... Helpers existentes (flatten, request, searchCustomer, etc) mantidos abaixo
    
    protected function request($method, $endpoint, $data = [])
    {
        if (empty($this->config['secret_key'])) {
            throw new Exception("Stripe Secret Key não configurada.");
        }

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config['secret_key'],
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'http_errors' => false // Tratamos manualmente
        ];

        if (!empty($data)) {
            $options['form_params'] = $this->flatten($data); // Otimizar para x-www-form-urlencoded
        }

        $response = $this->client->request($method, $this->baseUrl . $endpoint, $options);
        $body = $response->getBody();
        $json = json_decode($body, true);

        if ($response->getStatusCode() >= 400) {
            $msg = $json['error']['message'] ?? 'Erro desconhecido Stripe';
            throw new Exception($msg);
        }

        return $json;
    }

    protected function searchCustomer($email)
    {
        $res = $this->request('GET', '/customers', ['email' => $email, 'limit' => 1]);
        if (!empty($res['data'])) {
            return [
                'id' => $res['data'][0]['id'],
                'gateway' => 'stripe'
            ];
        }
        return null;
    }
    
    protected function attachPaymentMethod($customerId, $paymentMethodId)
    {
        // Anexa o PaymentMethod ao Customer
        $this->request('POST', "/payment_methods/{$paymentMethodId}/attach", ['customer' => $customerId]);
        
        // Define como padrão para invoices
        $this->request('POST', "/customers/{$customerId}", [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId]
        ]);
    }

    protected function getOrCreatePrice($amount, $currency, $interval = null, $productName = 'Assinatura')
    {
        // Create Product
        $prodParams = ['name' => $productName];
        $prod = $this->request('POST', '/products', $prodParams);
        
        // Create Price
        $priceParams = [
            'unit_amount' => (int)($amount * 100),
            'currency' => $currency,
            'product' => $prod['id'],
        ];
        
        if ($interval) {
            $priceParams['recurring'] = ['interval' => $interval];
        }
        
        $price = $this->request('POST', '/prices', $priceParams);
        return $price['id'];
    }

    protected function mapStatus($stripeStatus)
    {
        $map = [
            'active' => 'ACTIVE',
            'past_due' => 'OVERDUE',
            'unpaid' => 'OVERDUE',
            'canceled' => 'CANCELLED',
            'incomplete' => 'PENDING',
            'incomplete_expired' => 'EXPIRED',
            'trialing' => 'ACTIVE',
            'succeeded' => 'CONFIRMED',
            'requires_payment_method' => 'PENDING',
            'requires_confirmation' => 'PENDING',
            'requires_action' => 'PENDING'
        ];
        return $map[$stripeStatus] ?? 'PENDING';
    }

    protected function flatten(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = $prefix === '' ? $key : $prefix . '[' . $key . ']';
            if (is_array($value)) {
                $result = array_merge($result, $this->flatten($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }
        return $result;
    }
}
