<?php

namespace App\PaymentGateways;

interface GatewayInterface
{
    /**
     * Inicializar gateway com configurações
     * 
     * @param array $config Configurações do gateway (api_key, environment, etc)
     * @return void
     */
    public function configure(array $config): void;
    
    /**
     * Buscar cliente pelo CPF/CNPJ no gateway
     * 
     * @param string $document CPF ou CNPJ (apenas números)
     * @return string|null Customer ID do gateway ou null se não encontrado
     */
    public function findCustomerByDocument(string $document): ?string;

    /**
     * Criar cliente no gateway
     * 
     * @param array $customerData Dados do cliente (name, email, document, phone)
     * @return string Customer ID do gateway
     * @throws \Exception Se falhar na criação
     */
    public function createCustomer(array $customerData): string;

    /**
     * Atualizar dados do cliente no gateway
     * 
     * @param string $customerId ID do cliente no gateway
     * @param array $customerData Novos dados (name, email, document, phone)
     * @return bool
     */
    public function updateCustomer(string $customerId, array $customerData): bool;
    
    /**
     * Criar assinatura recorrente
     * 
     * @param string $customerId ID do cliente no gateway
     * @param string $planId ID do plano local
     * @param array $data Dados extras (billing_type, amount, description)
     * @return array ['subscription_id' => string, 'status' => string, 'next_billing_date' => string, 'payment_url' => string|null]
     * @throws \Exception Se falhar na criação
     */
    public function createSubscription(string $customerId, string $planId, array $data): array;
    
    /**
     * Criar pagamento único (turbo, avulso)
     * 
     * @param string $customerId ID do cliente no gateway
     * @param float $amount Valor do pagamento
     * @param array $data Dados extras (billing_type, description)
     * @return array ['payment_id' => string, 'status' => string, 'payment_url' => string, 'qr_code' => string|null]
     * @throws \Exception Se falhar na criação
     */
    public function createPayment(string $customerId, float $amount, array $data): array;
    
     /**
     * Cancelar assinatura
     * 
     * @param string $subscriptionId ID da assinatura no gateway
     * @return bool True se cancelou com sucesso
     */
    public function cancelSubscription(string $subscriptionId): bool;

    /**
     * Cancelar um pagamento avulso (Pix/Boleto/Cartão)
     * 
     * @param string $paymentId ID do pagamento no gateway
     * @return bool
     */
    public function cancelPayment(string $paymentId): bool;
    
    /**
     * Atualizar assinatura (troca de valor/plano)
     * 
     * @param string $subscriptionId ID da assinatura no gateway
     * @param array $data Novos dados (amount, description)
     * @return bool
     */
    public function updateSubscription(string $subscriptionId, array $data): bool;
    
    /**
     * Obter detalhes de uma assinatura específica
     * 
     * @param string $subscriptionId ID da assinatura no gateway
     * @return array|null Dados da assinatura ou null se não encontrada
     */
    public function getSubscription(string $subscriptionId): ?array;
    
    /**
     * Suspender assinatura
     * 
     * @param string $subscriptionId
     * @return bool
     */
    public function suspendSubscription(string $subscriptionId): bool;
    
    /**
     * Reativar assinatura suspensa
     * 
     * @param string $subscriptionId
     * @return bool
     */
    public function reactivateSubscription(string $subscriptionId): bool;
    
    /**
     * Processar webhook do gateway
     * 
     * @param array $payload Payload raw do webhook
     * @return array ['event_type' => string, 'reference_id' => string, 'status' => string, 'data' => array]
     * @throws \Exception Se payload inválido
     */
    public function handleWebhook(array $payload): array;
    
    /**
     * Obter métodos de pagamento suportados pelo gateway
     * 
     * @return array Ex: ['PIX', 'BOLETO', 'CREDIT_CARD']
     */
    public function getSupportedMethods(): array;
    
    /**
     * Validar configuração (testar credenciais)
     * 
     * @return bool True se configuração válida
     */
    public function validateConfig(): bool;
    
    /**
     * Obter código identificador do gateway
     * 
     * @return string Ex: 'asaas', 'stripe', 'mercadopago'
     */
    public function getCode(): string;
    
    /**
     * Obter assinatura ativa do cliente no gateway
     * 
     * @param string $customerId ID do cliente no gateway
     * @return array|null Dados da assinatura ou null se não encontrada
     */
    public function getActiveSubscription(string $customerId): ?array;

    /**
     * Obter nome amigável do gateway
     * 
     * @return string Ex: 'Asaas', 'Stripe', 'Mercado Pago'
     */
    public function getName(): string;

    /**
     * Obter cobranças pendentes do cliente no gateway
     * 
     * @param string $customerId ID do cliente no gateway
     * @return array Lista de pagamentos pendentes
     */
    public function getPendingPayments(string $customerId): array;
}
