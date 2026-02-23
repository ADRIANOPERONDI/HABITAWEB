<?php

namespace App\PaymentGateways;

class AsaasGateway implements GatewayInterface
{
    protected $apiKey;
    protected $environment;
    protected $webhookSecret;
    protected $baseUrl;
    
    public function configure(array $config): void
    {
        $this->apiKey = $config['api_key'] ?? '';
        $this->environment = $config['environment'] ?? 'sandbox';
        $this->webhookSecret = $config['webhook_secret'] ?? '';
        
        $this->baseUrl = $this->environment === 'production' 
            ? 'https://api.asaas.com/v3'
            : 'https://sandbox.asaas.com/api/v3';
    }

    public function findCustomerByDocument(string $document): ?string
    {
        try {
            $response = $this->request('GET', '/customers?cpfCnpj=' . urlencode($document));
            if (!empty($response['data'])) {
                return $response['data'][0]['id'];
            }
        } catch (\Exception $e) {
            log_message('error', 'Erro ao buscar cliente no Asaas: ' . $e->getMessage());
        }
        return null;
    }
    
    public function createCustomer(array $customerData): string
    {
        $response = $this->request('POST', '/customers', [
            'name' => $customerData['name'],
            'email' => $customerData['email'],
            'cpfCnpj' => $customerData['document'],
            'mobilePhone' => $customerData['phone'] ?? '',
            'postalCode' => $customerData['postalCode'] ?? '',
            'addressNumber' => $customerData['addressNumber'] ?? '',
            'externalReference' => $customerData['external_reference'] ?? ''
        ]);
        
        return $response['id'];
    }

    public function updateCustomer(string $customerId, array $customerData): bool
    {
        try {
            $this->request('POST', "/customers/{$customerId}", [
                'name' => $customerData['name'],
                'email' => $customerData['email'],
                'cpfCnpj' => $customerData['document'],
                'mobilePhone' => $customerData['phone'] ?? '',
                'externalReference' => $customerData['external_reference'] ?? ''
            ]);
            return true;
        } catch (\Exception $e) {
            log_message('error', 'Erro ao atualizar cliente Asaas: ' . $e->getMessage());
            return false;
        }
    }
    
    public function createSubscription(string $customerId, string $planId, array $data): array
    {
        $payload = [
            'customer' => $customerId,
            'billingType' => $data['billing_type'],
            'value' => $data['amount'],
            'cycle' => 'MONTHLY',
            'description' => $data['description'],
            'nextDueDate' => $data['next_due_date'] ?? date('Y-m-d', strtotime('+3 days')),
            'externalReference' => $data['external_reference'] ?? ''
        ];

        // Adicionar dados do cartão se fornecidos (Para tokenização na assinatura)
        if (isset($data['creditCard'])) {
            $payload['creditCard'] = $data['creditCard'];
        }
        
        if (isset($data['creditCardHolderInfo'])) {
            $payload['creditCardHolderInfo'] = $data['creditCardHolderInfo'];
        }

        // Se já tiveremos um token de cartão (caso tenha sido tokenizado antes)
        if (isset($data['creditCardToken'])) {
            $payload['creditCardToken'] = $data['creditCardToken'];
        }

        $response = $this->request('POST', '/subscriptions', $payload);

        // Buscar a primeira cobrança da assinatura para obter o link de pagamento e detalhes (PIX/Boleto)
        $invoiceUrl = null;
        $firstPayment = null;
        try {
            $payments = $this->request('GET', "/subscriptions/{$response['id']}/payments");
            if (!empty($payments['data'])) {
                $firstPayment = $payments['data'][0];
                $invoiceUrl = $firstPayment['invoiceUrl'] ?? null;
                
                // Se for PIX, buscar QR Code específico
                if ($firstPayment['billingType'] === 'PIX') {
                    try {
                        $qrResponse = $this->request('GET', "/payments/{$firstPayment['id']}/pixQrCode");
                        $firstPayment['pixQrCode'] = $qrResponse;
                    } catch (\Exception $e) { /* ignore */ }
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Erro ao buscar cobranças da assinatura Asaas: ' . $e->getMessage());
        }
        
        return [
            'subscription_id' => $response['id'],
            'status' => $response['status'],
            'next_billing_date' => $response['nextDueDate'],
            'payment_url' => $invoiceUrl,
            'first_payment' => $firstPayment
        ];
    }
    
    public function createPayment(string $customerId, float $amount, array $data): array
    {
        $payload = [
            'customer' => $customerId,
            'billingType' => $data['billing_type'],
            'value' => $amount,
            'dueDate' => $data['due_date'] ?? date('Y-m-d', strtotime('+3 days')),
            'description' => $data['description'],
            'externalReference' => $data['external_reference'] ?? ''
        ];

        // Tokenization support
        if (isset($data['tokenizeCreditCard']) && $data['tokenizeCreditCard']) {
            $payload['tokenizeCreditCard'] = true;
        }
        if (isset($data['creditCardToken'])) {
            $payload['creditCardToken'] = $data['creditCardToken'];
        }

        $response = $this->request('POST', '/payments', $payload);
        
        $result = [
            'payment_id' => $response['id'],
            'status' => $response['status'],
            'payment_url' => $response['invoiceUrl']
        ];
        
        // Se for PIX, buscar QR Code
        if ($data['billing_type'] === 'PIX') {
            try {
                $qr = $this->request('GET', "/payments/{$response['id']}/pixQrCode");
                $result['qr_code'] = $qr['payload'];
                $result['qr_code_image'] = $qr['encodedImage'];
            } catch (\Exception $e) {
                log_message('warning', 'Erro ao buscar QR Code PIX: ' . $e->getMessage());
            }
        }
        
        return $result;
    }
    
    public function cancelSubscription(string $subscriptionId): bool
    {
        try {
            $response = $this->request('DELETE', "/subscriptions/{$subscriptionId}");
            return $response['deleted'] ?? false;
        } catch (\Exception $e) {
            log_message('error', 'Erro ao cancelar assinatura Asaas: ' . $e->getMessage());
            return false;
        }
    }

    public function cancelPayment(string $paymentId): bool
    {
        try {
            $response = $this->request('DELETE', "/payments/{$paymentId}");
            return $response['deleted'] ?? false;
        } catch (\Exception $e) {
            log_message('error', 'Erro ao cancelar pagamento Asaas: ' . $e->getMessage());
            return false;
        }
    }
    
    public function updateSubscription(string $subscriptionId, array $data): bool
    {
        try {
            $updateData = [];
            if (isset($data['amount'])) $updateData['value'] = $data['amount'];
            if (isset($data['description'])) $updateData['description'] = $data['description'];
            
            if (empty($updateData)) return true;
            
            log_message('debug', "[AsaasGateway] Atualizando assinatura {$subscriptionId}: " . json_encode($updateData));
            $response = $this->request('POST', "/subscriptions/{$subscriptionId}", $updateData);
            return isset($response['id']);
        } catch (\Exception $e) {
            log_message('error', 'Erro ao atualizar assinatura Asaas: ' . $e->getMessage());
            return false;
        }
    }
    
    public function suspendSubscription(string $subscriptionId): bool
    {
        // No Asaas, 'suspender' pode ser feito via status ou cancelamento dependendo do fluxo.
        // A API suporta apenas active: false ou deletar. 
        // Para suspensão temporária, costuma-se deletar e recriar ou marcar como 'disabled'.
        // Vamos usar a deleção pois no Asaas v3 delete cancela a recorrência futura.
        return $this->cancelSubscription($subscriptionId);
    }
    
    public function reactivateSubscription(string $subscriptionId): bool
    {
        // Reativação no Asaas geralmente exige criar uma nova ou se o status for inativo.
        // Se foi cancelada/deletada, tem que criar de novo.
        // Para fins de interface, retornaremos falso e trataremos a reativação como "Novo Checkout"
        // ou implementaremos a lógica de recriação no Service se necessário.
        return false; 
    }
    
    public function handleWebhook(array $payload): array
    {
        $event = $payload['event'] ?? '';
        
        return [
            'event_type' => $event,
            'reference_id' => $payload['payment']['id'] ?? $payload['subscription']['id'] ?? null,
            'status' => $payload['payment']['status'] ?? $payload['subscription']['status'] ?? 'UNKNOWN',
            'data' => $payload
        ];
    }
    
    public function getSupportedMethods(): array
    {
        return ['PIX', 'BOLETO', 'CREDIT_CARD'];
    }
    
    public function validateConfig(): bool
    {
        try {
            $this->request('GET', '/customers?limit=1');
            return true;
        } catch (\Exception $e) {
            log_message('error', 'Validação Asaas falhou: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Buscar uma cobrança específica pelo ID
     */
    public function getPayment(string $paymentId): array
    {
        return $this->request('GET', "/payments/{$paymentId}");
    }

    public function getSubscription(string $subscriptionId): ?array
    {
        try {
            $response = $this->request('GET', "/subscriptions/{$subscriptionId}");
            return [
                'id' => $response['id'],
                'status' => $response['status'],
                'value' => $response['value'],
                'deleted' => $response['deleted'] ?? false,
                'nextDueDate' => $response['nextDueDate'] ?? null,
                'billingType' => $response['billingType'] ?? null,
                'cycle' => $response['cycle'] ?? null
            ];
        } catch (\Exception $e) {
            log_message('error', 'Erro ao buscar detalhes da assinatura Asaas: ' . $e->getMessage());
            return null;
        }
    }

    public function getActiveSubscription(string $customerId): ?array
    {
        try {
            $response = $this->request('GET', "/subscriptions?customer={$customerId}&status=ACTIVE");
            
            if (!empty($response['data'])) {
                $sub = $response['data'][0];
                $result = [
                    'subscription_id' => $sub['id'],
                    'status' => $sub['status'],
                    'amount' => $sub['value'],
                    'billing_type' => $sub['billingType'],
                    'next_billing_date' => $sub['nextDueDate'],
                    'external_reference' => $sub['externalReference'] ?? null
                ];

                // Buscar cobrança pendente para obter link de pagamento
                try {
                    $payments = $this->request('GET', "/subscriptions/{$sub['id']}/payments");
                    if (!empty($payments['data'])) {
                        // Procurar a primeira PENDING ou apenas a primeira da lista
                        $firstPayment = null;
                        foreach ($payments['data'] as $p) {
                            if ($p['status'] === 'PENDING') {
                                $firstPayment = $p;
                                break;
                            }
                        }
                        
                        // Fallback para a primeira se nenhuma pendente (talvez já paga)
                        if (!$firstPayment) $firstPayment = $payments['data'][0];

                        $result['payment_url'] = $firstPayment['invoiceUrl'] ?? null;
                        $result['first_payment'] = $firstPayment;

                        // Se for PIX, buscar QR Code específico
                        if ($firstPayment['billingType'] === 'PIX') {
                            try {
                                $qrResponse = $this->request('GET', "/payments/{$firstPayment['id']}/pixQrCode");
                                $result['first_payment']['pixQrCode'] = $qrResponse;
                            } catch (\Exception $e) { /* ignore */ }
                        }
                    }
                } catch (\Exception $e) {
                    log_message('error', 'Erro ao buscar cobranças da assinatura Asaas existente: ' . $e->getMessage());
                }

                return $result;
            }
        } catch (\Exception $e) {
            log_message('error', 'Erro ao buscar assinatura ativa no Asaas: ' . $e->getMessage());
        }
        
        return null;
    }
    
    public function getCode(): string
    {
        return 'asaas';
    }
    
    public function getName(): string
    {
        return 'Asaas';
    }

    public function getPendingPayments(string $customerId): array
    {
        try {
            // Buscamos as cobranças recentes do cliente (sem filtrar por PENDING para poder detectar as RECEBIDAS no sync)
            $response = $this->request('GET', "/payments?customer={$customerId}&limit=20");
            
            $payments = [];
            if (!empty($response['data'])) {
                foreach ($response['data'] as $p) {
                    $payments[] = [
                        'payment_id' => $p['id'],
                        'status' => $p['status'],
                        'amount' => $p['value'],
                        'billing_type' => $p['billingType'],
                        'dueDate' => $p['dueDate'],
                        'invoice_url' => $p['invoiceUrl'],
                        'description' => $p['description'] ?? '',
                        'external_reference' => $p['externalReference'] ?? ''
                    ];
                }
            }
            return $payments;
        } catch (\Exception $e) {
            log_message('error', 'Erro ao buscar pagamentos pendentes no Asaas: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Fazer requisição para API do Asaas
     */
    protected function request(string $method, string $endpoint, array $data = [])
    {
        $client = \Config\Services::curlrequest();
        
        $options = [
            'headers' => [
                'access_token' => $this->apiKey,
                'Content-Type' => 'application/json',
                'User-Agent' => 'Habitaweb/1.0 CodeIgniter4'
            ],
            'http_errors' => false
        ];
        
        if (!empty($data)) {
            $options['json'] = $data;
        }
        
        log_message('debug', "[AsaasGateway] Request: {$method} {$this->baseUrl}{$endpoint}");
        $response = $client->request($method, $this->baseUrl . $endpoint, $options);
        $rawBody = $response->getBody();
        $body = json_decode($rawBody, true);
        
        log_message('debug', "[AsaasGateway] Response Status: " . $response->getStatusCode());
        
        if ($response->getStatusCode() >= 400) {
            log_message('error', 'Asaas API Error - Status: ' . $response->getStatusCode());
            log_message('error', 'Asaas API Error - Body: ' . json_encode($body));
            log_message('error', 'Asaas API Error - URL: ' . $this->baseUrl . $endpoint);
            
            $errorMessage = $body['errors'][0]['description'] ?? 
                           $body['message'] ?? 
                           'Erro desconhecido no Asaas';
            throw new \Exception($errorMessage);
        }
        
        return $body;
    }
}
