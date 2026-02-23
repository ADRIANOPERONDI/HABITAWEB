<?php

namespace App\Services;

use App\Models\AccountModel;
use App\Models\SubscriptionModel;
use App\Models\PaymentGatewayModel;
use App\Models\PaymentGatewayConfigModel;
use App\PaymentGateways\GatewayInterface;
use CodeIgniter\Config\Factories;

class PaymentService
{
    protected $gatewayModel;
    protected $configModel;
    protected $activeGateway;
    protected $accountModel;
    protected $subscriptionModel;
    protected $transactionModel;
    protected $paymentProfileModel;
    protected $db;

    public function __construct()
    {
        $this->gatewayModel = new PaymentGatewayModel();
        $this->configModel = new PaymentGatewayConfigModel();
        $this->accountModel = Factories::models(AccountModel::class);
        $this->subscriptionModel = Factories::models(SubscriptionModel::class);
        $this->transactionModel = model('App\Models\PaymentTransactionModel');
        $this->paymentProfileModel = model('App\Models\PaymentProfileModel');
        $this->db = \Config\Database::connect();
        
        // Load the primary active gateway immediately
        try {
            $this->activeGateway = $this->loadActiveGateway();
        } catch (\Exception $e) {
            log_message('error', 'Failed to load active payment gateway: ' . $e->getMessage());
            $this->activeGateway = null;
        }
    }

    /**
     * Load the primary active gateway
     */
    protected function loadActiveGateway()
    {
        $gateway = $this->gatewayModel->getPrimaryGateway();
        
        if (!$gateway) {
            throw new \Exception('Nenhum gateway de pagamento ativo configurado.');
        }
        
        return $this->instantiateGateway($gateway);
    }
    
    /**
     * Instantiate a specific gateway with its configuration
     */
    protected function instantiateGateway($gateway)
    {
        // Instanciar a classe do gateway
        $className = $gateway->class_name;
        if (!class_exists($className)) {
            throw new \Exception("Gateway class {$className} não encontrada.");
        }
        
        $instance = new $className();
        
        if (!($instance instanceof GatewayInterface)) {
            throw new \Exception("Gateway {$className} must implement GatewayInterface.");
        }
        
        // Buscar configurações
        $configs = $this->configModel->getGatewayConfig($gateway->id, true); // true = decrypted
        
        $instance->configure($configs);
        
        return $instance;
    }

    /**
     * Force specific gateway active (Runtime switching)
     */
    public function setGateway(string $code)
    {
        $gateway = $this->gatewayModel->where('code', $code)->first();
        if (!$gateway) {
            throw new \Exception("Gateway '$code' não encontrado.");
        }
        
        $this->activeGateway = $this->instantiateGateway($gateway);
        return $this;
    }

    /**
     * Get current active gateway instance
     */
    public function getActiveGateway(): ?GatewayInterface
    {
        return $this->activeGateway;
    }

    /**
     * Get or Create Customer on the active gateway
     */
    public function getOrCreateCustomer($accountId)
    {
        if (!$this->activeGateway) {
            throw new \Exception("Serviço de pagamento indisponível temporariamente.");
        }

        $account = $this->accountModel->find($accountId);
        
        if (!$account) {
            throw new \Exception("Conta não encontrada.");
        }
        
        // Check if we already have a customer ID for this gateway
        // We need to look at payment_transactions or improve subscription model to store gateway_code
        // For now, let's use the same logic as before but trying to be smarter or just creating/updating
        // Ideally we should store (gateway_code, customer_id) in a related table.
        // Given current structure, let's check recent valid subscription for this gateway
        
        /* 
           TODO: Improved Logic
           Ideally, we should rely on a table `account_gateway_profiles`
           (account_id, gateway_code, external_customer_id)
           
           For now, let's assume we create/update ensuring validity.
           Most gateways return the existing ID if email/cpf matches or update it.
           Asaas does this.
        */

        $cpfCnpj = preg_replace('/[^0-9]/', '', $account->documento ?? '');

        // Validação obrigatória de CPF/CNPJ
        if (empty($cpfCnpj)) {
            throw new \Exception("Sua conta não possui CPF/CNPJ cadastrado. Por favor, complete seu perfil antes de assinar um plano.");
        }

        // 1. Tentar buscar localmente em assinaturas anteriores da conta
        $gatewayCode = $this->activeGateway->getCode();
        
        $existingSub = $this->subscriptionModel->findMostRecentByAccount($accountId);

        if ($existingSub) {
            return $existingSub->asaas_customer_id;
        }

        // 2. Se não achou no banco local, buscar no gateway pelo CPF/CNPJ
        log_message('debug', '[PaymentService] Buscando cliente no gateway por documento: ' . $cpfCnpj);
        $gatewayCustomerId = $this->activeGateway->findCustomerByDocument($cpfCnpj);
        
        $customerData = [
            'name' => $account->nome,
            'email' => $account->email,
            'document' => $cpfCnpj,
            'phone' => preg_replace('/[^0-9]/', '', $account->whatsapp ?? $account->telefone ?? ''),
            'external_reference' => (string)$account->id
        ];

        if ($gatewayCustomerId) {
            log_message('debug', '[PaymentService] Cliente encontrado no gateway: ' . $gatewayCustomerId . '. Sincronizando dados...');
            // Sincronizar dados atuais (Nome, Email, External ID) para evitar confusão no Dashboard
            $this->activeGateway->updateCustomer($gatewayCustomerId, $customerData);
            return $gatewayCustomerId;
        }

        // 3. Caso contrário, criar novo cliente
        log_message('debug', '[PaymentService] Cliente não encontrado. Criando novo no gateway...');
        try {
            $newCustomerId = $this->activeGateway->createCustomer($customerData);
            log_message('debug', '[PaymentService] Novo cliente criado: ' . $newCustomerId);
            return $newCustomerId;
        } catch (\Exception $e) {
            log_message('error', '[PaymentService] Erro ao criar cliente no gateway: ' . $e->getMessage());
            throw new \Exception("Erro ao processar cadastro no operador de pagamento: " . $e->getMessage());
        }
    }

    /**
     * Initialize a Subscription (Plan)
     */
    public function initializeSubscription(int $accountId, int $planId, string $billingType, array $creditCard = [], ?string $couponCode = null)
    {
        if (!$this->activeGateway) {
            throw new \Exception("Serviço de pagamento indisponível.");
        }

        $customerId = $this->getOrCreateCustomer($accountId);
        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->find($planId);

        if (!$plan) {
            throw new \Exception("Plano inválido.");
        }
        
        // 1. Validate Coupon
        $couponData = $this->validateCoupon($couponCode, (float)$plan->preco_mensal);
        $finalAmount = $couponData['final_value'];
        $coupon = $couponData['coupon'] ?? null;
        
        // Prepare generic data for gateway
        $data = [
            'billing_type' => $billingType, // PIX, BOLETO, CREDIT_CARD
            'amount' => $finalAmount,
            'description' => "Assinatura Plano " . $plan->nome . ($coupon ? " (Cupom: {$coupon->code})" : ""),
            'external_reference' => 'plan_' . $planId . '_acc_' . $accountId
        ];
        
        if (!empty($creditCard)) {
            $data['creditCard'] = $creditCard;
            
            // Build Holder Info if not provided
            if (isset($creditCard['holderInfo'])) {
                $data['creditCardHolderInfo'] = $creditCard['holderInfo'];
            } else {
                // Fetch account to fill holder info as fallback
                $accountForHolder = $this->accountModel->find($accountId);
                if ($accountForHolder) {
                    $data['creditCardHolderInfo'] = [
                        'name' => $creditCard['holderName'] ?? $accountForHolder->nome,
                        'email' => $accountForHolder->email,
                        'cpfCnpj' => preg_replace('/[^0-9]/', '', $accountForHolder->documento),
                        'postalCode' => $accountForHolder->cep ?? '',
                        'addressNumber' => $accountForHolder->numero ?? '',
                        'phone' => preg_replace('/[^0-9]/', '', $accountForHolder->whatsapp ?? $accountForHolder->telefone ?? '')
                    ];
                }
            }

            // Universal Token Mapping (Stripe / MercadoPago)
            if (isset($creditCard['token'])) {
                $data['paymentMethodId'] = $creditCard['token']; // Stripe
                $data['cardToken'] = $creditCard['token'];       // MercadoPago
                $data['creditCardToken'] = $creditCard['token']; // Asaas (sometimes)
            }
        }

        try {
            // Check if user already has an active subscription in the gateway
            log_message('debug', '[PaymentService] Verificando assinaturas ativas no gateway para cliente ' . $customerId);
            $activeSub = $this->activeGateway->getActiveSubscription($customerId);
            
            // Se já tem assinatura ativa no gateway, vamos ADOTAR ela obrigatoriamente
            // Isso garante que se o cliente já paga por um plano no CNPJ/CPF dele, o sistema se vincula a ele
            if ($activeSub) {
                $expectedRefMatch = 'plan_' . $planId . '_acc_' . $accountId;
                $currentRef = $activeSub['external_reference'] ?? '';
                
                // Antes éramos rigorosos, agora somos integradores: Se existe ativa, ADOTAMOS.
                // Apenas logamos se a referência for diferente para fins de auditoria.
                if ($currentRef !== $expectedRefMatch) {
                    log_message('notice', "[PaymentService] ADOTANDO assinatura existente no gateway com referência divergente. Atual: {$currentRef}, Esperada: {$expectedRefMatch}");
                } else {
                    log_message('notice', '[PaymentService] Cliente ' . $customerId . ' já possui uma assinatura ativa IDÊNTICA no gateway: ' . $activeSub['subscription_id']);
                }
                
                $subscriptionData = $activeSub;
            } else {
                log_message('debug', '[PaymentService] Criando assinatura no gateway para cliente ' . $customerId);
                $subscriptionData = $this->activeGateway->createSubscription($customerId, (string)$planId, $data);
                log_message('debug', '[PaymentService] Assinatura criada com sucesso no gateway: ' . $subscriptionData['subscription_id']);
            }
        } catch (\Exception $e) {
            log_message('error', '[PaymentService] Erro ao criar assinatura no gateway: ' . $e->getMessage());
            throw new \Exception("Erro no gateway: " . $e->getMessage());
        }

        $subId = $subscriptionData['subscription_id'];
        $status = strtoupper($subscriptionData['status'] ?? 'ACTIVE');
        
        $subscriptionFields = [
            'account_id' => $accountId,
            'plan_id' => $planId,
            'status' => $status, 
            'data_inicio' => date('Y-m-d'),
            'data_fim' => date('Y-m-d', strtotime('+30 days')),
            'valor' => $finalAmount, 
            'asaas_subscription_id' => $subId, 
            'asaas_customer_id' => $customerId,
            'payment_method' => $billingType,
            'next_billing_date' => $subscriptionData['next_billing_date'] ?? null
        ];

        // LOGICA DE INTEGRIDADE ABSOLUTA: Upsert baseado no asaas_subscription_id
        $existingLocal = $this->subscriptionModel->where('asaas_subscription_id', $subId)->first();
        
        if ($existingLocal) {
            log_message('notice', "[PaymentService] Atualizando assinatura local existente ID {$existingLocal->id} vinculada ao Asaas {$subId}");
            $this->subscriptionModel->update($existingLocal->id, $subscriptionFields);
            $localSubId = $existingLocal->id;
        } else {
            log_message('notice', "[PaymentService] Criando novo registro local para assinatura Asaas {$subId}");
            $this->subscriptionModel->insert($subscriptionFields);
            $localSubId = $this->subscriptionModel->getInsertID();
        }

        // LIMPEZA: Desativar qualquer outra assinatura ATIVA local que não seja esta
        $this->subscriptionModel->where('account_id', $accountId)
                               ->where('id !=', $localSubId)
                               ->where('status', 'ACTIVE')
                               ->set(['status' => 'INACTIVE'])
                               ->update();

        // Log transaction
        $this->transactionModel->upsertTransaction([
            'account_id' => $accountId,
            'gateway_transaction_id' => $subscriptionData['first_payment']['id'] ?? null, 
            'gateway' => $this->activeGateway->getCode(),
            'gateway_customer_id' => $customerId,
            'gateway_subscription_id' => $subId,
            'payment_method' => $billingType,
            'amount' => $finalAmount,
            'status' => 'PENDING',
            'type' => 'SUBSCRIPTION',
            'subscription_id' => $localSubId,
            'metadata' => [
                'gateway' => $this->activeGateway->getName(),
                'coupon' => $coupon ? $coupon->code : null,
                'original_amount' => $plan->preco_mensal,
                'invoice_url' => $subscriptionData['payment_url'] ?? null
            ]
        ]);
        
        // Register Coupon Usage
        if ($coupon) {
            $couponModel = new \App\Models\CouponModel();
            $couponModel->registerUsage(
                $coupon->id,
                $accountId,
                null, // Transaction ID do we have local ID of transaction? Ideally yes but insertID above is tricky if complex.
                      // Actually we don't return transaction ID from insert above easily unless getInsertID works on query builder insert.
                      // Let's use getInsertID() on builder if possible or just ignore specialized log relation for now.
                $couponData['discount_amount']
            );
        }
        
        return [
            'success' => true,
            'subscription' => [
                'subscription_id' => $subId,
                'value' => $finalAmount,
                'billingType' => $billingType,
                'status' => $status,
                'payment_url' => $subscriptionData['payment_url'] ?? null,
                'first_payment' => $subscriptionData['first_payment'] ?? null
            ],
            'local_id' => $localSubId
        ];
    }
    
    /**
     * Get active gateway name (for UI)
     */
    public function getActiveGatewayName()
    {
        return $this->activeGateway ? $this->activeGateway->getName() : 'Indisponível';
    }

    /**
     * Cancelar um pagamento (charge) no gateway
     */
    public function cancelPayment(int $subscriptionId)
    {
        // Este método agora é um atalho para cancelSubscription para garantir atomicidade
        return $this->cancelSubscription($subscriptionId);
    }

    /**
     * Cancelamento Robusto: Assinatura + Cobranças Pendentes (Integade Total)
     */
    public function cancelSubscription(int $subscriptionId)
    {
        $subscription = $this->subscriptionModel->find($subscriptionId);
        
        if (!$subscription) {
            log_message('error', "[PaymentService] Tentativa de cancelar assinatura inexistente localmente ID: $subscriptionId");
            return false;
        }

        $success = true;
        log_message('notice', "[PaymentService] Iniciando cancelamento atômico da assinatura ID: $subscriptionId (Conta: {$subscription->account_id})");

        // 1. Cancelar Assinatura no Gateway
        if ($subscription->asaas_subscription_id) {
            try {
                log_message('debug', "[PaymentService] Cancelando assinatura no Gateway: {$subscription->asaas_subscription_id}");
                $this->activeGateway->cancelSubscription($subscription->asaas_subscription_id);
            } catch (\Exception $e) {
                log_message('error', "[PaymentService] Erro ao cancelar assinatura {$subscription->asaas_subscription_id} no gateway: " . $e->getMessage());
                // Não paramos aqui, tentamos cancelar as cobranças soltas
            }
        }

        // 2. Localizar e Cancelar TODAS as cobranças pendentes vinculadas a esta assinatura no Asaas
        if ($subscription->asaas_customer_id) {
            try {
                // Buscamos pagamentos pendentes deste cliente diretamente no gateway para não depender de transações locais incompletas
                $pendingPayments = $this->activeGateway->getPendingPayments($subscription->asaas_customer_id);
                
                foreach ($pendingPayments as $payment) {
                    // Verificamos se o pagamento pertence a esta assinatura (se aplicável)
                    if (isset($payment['subscription']) && $payment['subscription'] === $subscription->asaas_subscription_id) {
                        try {
                            log_message('debug', "[PaymentService] Cancelando cobrança avulsa pendente {$payment['id']} vinculada à assinatura");
                            $this->activeGateway->cancelPayment($payment['id']);
                            
                            // Atualizamos a transação local se ela existir
                            $this->transactionModel->where('gateway_transaction_id', $payment['id'])->set(['status' => 'CANCELLED'])->update();
                        } catch (\Exception $e) {
                            log_message('error', "[PaymentService] Erro ao cancelar cobrança {$payment['id']}: " . $e->getMessage());
                        }
                    }
                }
            } catch (\Exception $e) {
                log_message('error', "[PaymentService] Erro ao buscar/cancelar cobranças pendentes: " . $e->getMessage());
            }
        }

        // 3. Busca transações locais pendentes por ID de assinatura e cancela
        $this->transactionModel->where('subscription_id', $subscriptionId)
                              ->where('status', 'PENDING')
                              ->set(['status' => 'CANCELLED'])
                              ->update();

        // 4. Marca localmente como cancelado
        $this->subscriptionModel->update($subscriptionId, ['status' => 'CANCELLED']);

        log_message('notice', "[PaymentService] Cancelamento atômico concluído para ID: $subscriptionId");
        return $success;
    }

    /**
     * Suspender uma assinatura via Admin
     */
    public function suspendSubscription(int $subscriptionId)
    {
        $subscription = $this->subscriptionModel->find($subscriptionId);
        if (!$subscription || !$subscription->asaas_subscription_id) {
            throw new \Exception("Assinatura não encontrada.");
        }

        try {
            if ($this->activeGateway->suspendSubscription($subscription->asaas_subscription_id)) {
                $this->subscriptionModel->update($subscriptionId, ['status' => 'SUSPENDED']);
                
                // Opcionalmente suspender a conta também
                $this->accountModel->update($subscription->account_id, ['status' => 'SUSPENDED']);
                return true;
            }
            return false;
        } catch (\Exception $e) {
            throw new \Exception("Erro ao suspender no gateway: " . $e->getMessage());
        }
    }

    /**
     * Trocar plano de uma assinatura (Upgrade/Downgrade) com lógica de pró-rata
     */
    public function changeSubscriptionPlan(int $accountId, int $newPlanId)
    {
        $subscription = $this->subscriptionModel->where('account_id', $accountId)
                                               ->where('status', 'ACTIVE')
                                               ->first();

        if (!$subscription) {
            throw new \Exception("Assinatura ativa não encontrada.");
        }

        $planModel = model('App\Models\PlanModel');
        $newPlan = $planModel->find($newPlanId);
        if (!$newPlan) {
            throw new \Exception("Novo plano não encontrado.");
        }

        $oldPlan = $planModel->find($subscription->plan_id);
        $isUpgrade = $newPlan->preco_mensal > $oldPlan->preco_mensal;

        try {
            if ($isUpgrade && $subscription->asaas_subscription_id) {
                // 1. Lógica de Upgrade com Pró-rata
                $proRata = $this->calculateUpgradeProRata($subscription, (float)$oldPlan->preco_mensal, (float)$newPlan->preco_mensal);
                
                if ($proRata['value'] > 0) {
                    log_message('debug', "[PaymentService] Gerando cobrança de pró-rata: R$ " . $proRata['value']);
                    
                    $data = [
                        'billing_type' => $subscription->payment_method ?? 'PIX',
                        'description' => "Pro-rata Upgrade: " . $oldPlan->nome . " -> " . $newPlan->nome,
                        'external_reference' => "prorata_acc_{$accountId}_sub_{$subscription->id}"
                    ];
                    // Criar pagamento avulso
                    try {
                        $paymentData = $this->activeGateway->createPayment($subscription->asaas_customer_id, $proRata['value'], $data);
                        
                        // 2. Registrar transação no banco
                        $this->transactionModel->insert([
                            'account_id'      => $accountId,
                            'subscription_id' => $subscription->id,
                            'gateway'         => $this->activeGateway->getCode(),
                            'gateway_transaction_id' => $paymentData['payment_id'],
                            'gateway_customer_id'    => $subscription->asaas_customer_id,
                            'amount'          => $proRata['value'],
                            'status'          => 'PENDING',
                            'type'            => 'UPGRADE_PRORATA',
                            'payment_method'  => $subscription->payment_method ?? 'PIX',
                            'invoice_url'     => $paymentData['payment_url'],
                            'metadata'        => [
                                'description'  => $data['description'],
                                'invoice_url'  => $paymentData['payment_url'],
                                'old_plan_id'  => $oldPlan->id,
                                'old_price'    => $oldPlan->preco_mensal
                            ]
                        ]);

                    } catch (\Exception $e) {
                        log_message('error', '[PaymentService] Erro ao gerar cobrança de pró-rata: ' . $e->getMessage());
                    }
                }
            }

            // 2. Atualizar valor da assinatura no Gateway para os próximos ciclos
            if ($subscription->asaas_subscription_id) {
                $updateData = [
                    'amount' => (float)$newPlan->preco_mensal,
                    'description' => "Assinatura Plano " . $newPlan->nome
                ];
                $this->activeGateway->updateSubscription($subscription->asaas_subscription_id, $updateData);
            }

            // 3. Atualizar assinatura local
            $this->subscriptionModel->update($subscription->id, [
                'plan_id' => $newPlanId,
                'status' => 'ACTIVE' // Garantir ativa
            ]);

            log_message('notice', "[PaymentService] Plano da conta {$accountId} alterado para {$newPlan->nome}. Upgrade: " . ($isUpgrade ? 'Sim' : 'Não'));

            return true;
        } catch (\Exception $e) {
            log_message('error', '[PaymentService] Erro ao trocar plano: ' . $e->getMessage());
            throw new \Exception("Erro na troca de plano: " . $e->getMessage());
        }
    }

    /**
     * Calcula o valor proporcional para upgrade
     */
    protected function calculateUpgradeProRata($subscription, float $oldPrice, float $newPrice): array
    {
        // Se não tiver data de próximo pagamento, não temos como calcular pró-rata.
        // Assumimos 30 dias de ciclo.
        $nextDate = $subscription->proximo_pagamento ?? date('Y-m-d', strtotime('+30 days'));
        
        $today = new \DateTime();
        $target = new \DateTime($nextDate);
        $diff = $today->diff($target);
        $daysRemaining = $diff->days;

        if ($daysRemaining <= 0) return ['value' => 0];

        $monthlyDiff = $newPrice - $oldPrice;
        $dailyDiff = $monthlyDiff / 30;
        $proRataValue = round($dailyDiff * $daysRemaining, 2);

        return [
            'value' => max(0, $proRataValue),
            'days_remaining' => $daysRemaining
        ];
    }

    /**
     * Validar e Calcular Desconto de Cupom
     */
    public function validateCoupon(?string $code, float $originalValue)
    {
        if (empty($code)) {
            return ['valid' => false, 'discount_amount' => 0, 'final_value' => $originalValue, 'coupon' => null];
        }

        $couponModel = new \App\Models\CouponModel();
        $coupon = $couponModel->getValidCoupon($code);

        if (!$coupon) {
            return ['valid' => false, 'message' => 'Cupom inválido ou expirado.', 'discount_amount' => 0, 'final_value' => $originalValue, 'coupon' => null];
        }

        $discount = 0;
        if ($coupon->discount_type === 'percent') {
            $discount = $originalValue * ($coupon->discount_value / 100);
        } else {
            $discount = $coupon->discount_value;
        }

        // Garantir que não fique negativo
        if ($discount > $originalValue) {
            $discount = $originalValue;
        }

        return [
            'valid' => true,
            'coupon' => $coupon,
            'discount_amount' => $discount,
            'final_value' => max(0, $originalValue - $discount)
        ];
    }
    /**
     * Start a Tokenization Payment (Manual Recurrence Flow)
     */
    public function initiateTokenizationPayment(int $accountId, int $planId, string $billingType, ?string $couponCode = null)
    {
        if (!$this->activeGateway) {
            throw new \Exception("Serviço de pagamento indisponível.");
        }

        $customerId = $this->getOrCreateCustomer($accountId);
        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->find($planId);

        if (!$plan) {
            throw new \Exception("Plano inválido.");
        }

        // 1. Validate Coupon
        $couponData = $this->validateCoupon($couponCode, (float)$plan->preco_mensal);
        $finalAmount = $couponData['final_value'];
        $coupon = $couponData['coupon'] ?? null;

        // 2. Prepare Data for One-Time Charge
        $data = [
            'billing_type' => $billingType,
            'description' => "Assinatura Plano " . $plan->nome . " (Primeira Fatura)",
            'external_reference' => 'init_' . $planId . '_acc_' . $accountId . '_' . time(),
            'due_date' => date('Y-m-d'), // Due today
            'tokenizeCreditCard' => ($billingType === 'CREDIT_CARD') // Request token if CC
        ];

        // 3. Create Payment (Charge)
        try {
            $paymentData = $this->activeGateway->createPayment($customerId, $finalAmount, $data);
        } catch (\Exception $e) {
            throw new \Exception("Erro no gateway: " . $e->getMessage());
        }

        // 4. Create PENDING Subscription (Waiting for Payment)
        $subscription = [
            'account_id' => $accountId,
            'plan_id' => $planId,
            'status' => 'PENDING', // Will activate on Webhook
            'data_inicio' => date('Y-m-d'),
            'data_fim' => date('Y-m-d', strtotime('+30 days')),
            'valor' => $finalAmount,
            'asaas_subscription_id' => null, // Managed manually
            'asaas_customer_id' => $customerId,
            'payment_method' => $billingType,
            'next_billing_date' => date('Y-m-d', strtotime('+30 days'))
        ];

        $this->subscriptionModel->insert($subscription);
        $localSubId = $this->subscriptionModel->getInsertID();

        // 5. Store Transaction Reference
        $this->transactionModel->insert([
            'account_id' => $accountId,
            'gateway_transaction_id' => $paymentData['payment_id'],
            'gateway' => $this->activeGateway->getCode(),
            'gateway_customer_id' => $customerId,
            'gateway_subscription_id' => null, // One-time charge
            'payment_method' => $billingType,
            'amount' => $finalAmount,
            'status' => 'PENDING',
            'type' => 'TOKENIZATION_CHARGE', // Marker for webhook
            'subscription_id' => $localSubId,
            'metadata' => [
                'gateway' => $this->activeGateway->getName(),
                'coupon' => $coupon ? $coupon->code : null,
                'invoice_url' => $paymentData['payment_url']
            ]
        ]);

        return [
            'success'      => true,
            'subscription' => [
                'value'       => $finalAmount,
                'billingType' => $billingType
            ],
            'invoiceUrl'   => $paymentData['payment_url'],
            'local_id'     => $localSubId
        ];
    }

    /**
     * Preview de pró-rata para upgrade
     */
    public function previewUpgradeProRata(int $accountId, int $newPlanId): array
    {
        $subscription = $this->subscriptionModel->where('account_id', $accountId)
                                               ->where('status', 'ACTIVE')
                                               ->first();

        if (!$subscription) return ['value' => 0];

        $planModel = model('App\Models\PlanModel');
        $newPlan = $planModel->find($newPlanId);
        $oldPlan = $planModel->find($subscription->plan_id);

        if (!$newPlan || !$oldPlan) return ['value' => 0];

        return $this->calculateUpgradeProRata($subscription, (float)$oldPlan->preco_mensal, (float)$newPlan->preco_mensal);
    }

    /**
     * Sincroniza o status da assinatura (Double Verification)
     * Garante que o status local reflete o status real no gateway.
     */
    public function syncSubscriptionStatus(int $subscriptionId): bool
    {
        if (!$this->activeGateway) return false;

        $subscription = $this->subscriptionModel->find($subscriptionId);
        
        if (!$subscription || !$subscription->asaas_subscription_id) {
            return false;
        }

        try {
            log_message('debug', "[PaymentService] Sincronizando assinatura #{$subscriptionId} (Gateway: {$subscription->asaas_subscription_id})");
            
            // 1. Buscar status da assinatura no Gateway
            $remoteSub = $this->activeGateway->getSubscription($subscription->asaas_subscription_id);
            $remoteStatus = strtoupper($remoteSub['status'] ?? 'UNKNOWN');
            $isDeleted = $remoteSub['deleted'] ?? false;
            $localStatus = strtoupper($subscription->status);

            log_message('debug', "[PaymentService] Mismatch Check: Local status is '{$localStatus}', Asaas status is '{$remoteStatus}'");
            
            // 2. Se a assinatura estiver ATIVA no Gateway mas PENDENTE localmente,
            // vamos verificar se já existe algum pagamento RECEBIDO para ativá-la.
            if ($remoteStatus === 'ACTIVE' && in_array($localStatus, ['PENDING', 'AWAITING_PAYMENT'])) {
                log_message('notice', "[PaymentService] Assinatura ativa no Gateway mas pendente local. Verificando pagamentos...");
                
                $payments = $this->activeGateway->request('GET', "/subscriptions/{$subscription->asaas_subscription_id}/payments");
                if (!empty($payments['data'])) {
                    foreach ($payments['data'] as $p) {
                        if (in_array(strtoupper($p['status']), ['RECEIVED', 'CONFIRMED'])) {
                            log_message('notice', "[PaymentService] Pagamento Confirmado encontrado (#{$p['id']}). Ativando assinatura localmente.");
                            return $this->activateSubscriptionByAsaasId($subscription->asaas_subscription_id, $p['id']);
                        }
                    }
                }
            }

            // 3. Sincronização padrão de estados (Ativo -> Inativo, etc)
            $newStatus = null;
            if ($isDeleted && !in_array($localStatus, ['CANCELLED', 'DELETED'])) {
                $newStatus = 'CANCELLED';
            } elseif ($remoteStatus !== $localStatus) {
                if (in_array($remoteStatus, ['EXPIRED', 'INACTIVE']) && $localStatus === 'ACTIVE') {
                    $newStatus = ($remoteStatus === 'EXPIRED') ? 'EXPIRED' : 'CANCELLED';
                }
                
                if ($remoteStatus === 'ACTIVE' && in_array($localStatus, ['CANCELLED', 'SUSPENDED', 'EXPIRED', 'PENDING', 'AWAITING_PAYMENT'])) {
                    $newStatus = 'ACTIVE';
                }
            }
            
            // Housekeeping: Se está ATIVA lá e ATIVA aqui, mas queremos garantir que o banco está limpo
            if ($remoteStatus === 'ACTIVE' && $localStatus === 'ACTIVE') {
                log_message('debug', "[PaymentService] Ambas ATIVAS. Rodando faxina de registros órfãos para conta {$subscription->account_id}");
                $this->activateSubscriptionByAsaasId($subscription->asaas_subscription_id);
                return true; 
            }
            
            if ($newStatus) {
                log_message('notice', "[PaymentService] Syncing Sub #{$subscriptionId} from {$localStatus} to {$newStatus}");
                $this->subscriptionModel->update($subscriptionId, ['status' => $newStatus]);
                
                if (in_array($newStatus, ['CANCELLED', 'EXPIRED', 'INACTIVE'])) {
                     // SÓ desativa a conta se NÃO houver outra assinatura ATIVA
                     $otherActive = $this->subscriptionModel->where('account_id', $subscription->account_id)
                                                           ->where('status', 'ACTIVE')
                                                           ->where('id !=', $subscription->id)
                                                           ->countAllResults();
                     
                     if ($otherActive === 0) {
                         log_message('notice', "[PaymentService] Account {$subscription->account_id} marked as INACTIVE (No more active subscriptions).");
                         $this->accountModel->update($subscription->account_id, ['status' => 'INACTIVE']);
                     } else {
                         log_message('debug', "[PaymentService] Account {$subscription->account_id} remains ACTIVE (Has {$otherActive} other active subscriptions).");
                     }
                }
                return true;
            }

        } catch (\Exception $e) {
            log_message('error', "[PaymentService] Error syncing subscription status: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Ativação Atômica via ID do Asaas (Ideal para Webhooks e Fallbacks)
     */
    public function activateSubscriptionByAsaasId(string $asaasSubId, ?string $asaasPaymentId = null): bool
    {
        $subscription = $this->subscriptionModel->where('asaas_subscription_id', $asaasSubId)->first();
        if (!$subscription) return false;

        $this->subscriptionModel->update($subscription->id, [
            'status' => 'ACTIVE',
            'next_billing_date' => date('Y-m-d', strtotime('+30 days'))
        ]);

        if ($subscription->account_id) {
            $this->accountModel->update($subscription->account_id, ['status' => 'ACTIVE']);
        }

        // 1. Limpar outras transações PENDENTES para esta conta
        $this->transactionModel->where('account_id', $subscription->account_id)
                              ->whereIn('status', ['PENDING', 'AWAITING_PAYMENT'])
                              ->set(['status' => 'CANCELLED'])
                              ->update();

        // 2. Limpar outras ASSINATURAS PENDENTES para esta conta
        // Isso resolve o "dashboard fantasma" com avisos de cobrança em aberto
        $this->subscriptionModel->where('account_id', $subscription->account_id)
                               ->where('id !=', $subscription->id)
                               ->whereIn('status', ['PENDING', 'AWAITING_PAYMENT'])
                               ->set(['status' => 'CANCELLED'])
                               ->update();

        if ($asaasPaymentId) {
            // Verificar se já existe essa transação localmente
            $localTx = $this->transactionModel->where('gateway_transaction_id', $asaasPaymentId)->first();
            
            if (!$localTx) {
                log_message('notice', "[PaymentService] Transação paga {$asaasPaymentId} não encontrada localmente. Criando registro de emergência...");
                
                // Buscar detalhes do pagamento no Asaas se possível
                $pData = [];
                try {
                    $pData = $this->activeGateway->request('GET', "/payments/{$asaasPaymentId}");
                } catch (\Exception $e) {
                    log_message('error', "[PaymentService] Falha ao detalhar pagamento para registro de emergência: " . $e->getMessage());
                }

                $this->transactionModel->insert([
                    'account_id'      => $subscription->account_id,
                    'subscription_id' => $subscription->id,
                    'gateway'         => $this->activeGateway->getCode(),
                    'gateway_transaction_id' => $asaasPaymentId,
                    'gateway_customer_id'    => $subscription->asaas_customer_id,
                    'amount'          => $pData['value'] ?? 0,
                    'status'          => 'SUCCESS',
                    'type'            => 'SUBSCRIPTION',
                    'payment_method'  => $pData['billingType'] ?? 'UNDEFINED',
                    'metadata'        => json_encode($pData)
                ]);
            } else {
                $this->transactionModel->where('gateway_transaction_id', $asaasPaymentId)
                                      ->set(['status' => 'SUCCESS'])
                                      ->update();
            }
        }

        log_message('notice', "[PaymentService] Assinatura #{$subscription->id} ativada e transações pendentes limpas.");
        return true;
    }

    /**
     * Sincroniza pagamentos pendentes do gateway para o banco local
     */
    public function syncPendingPayments(int $accountId): void
    {
        if (!$this->activeGateway) return;

        $subscription = $this->subscriptionModel->where('account_id', $accountId)->first();
        
        // Tentar obter o customer ID (da assinatura ou pela conta)
        $customerId = null;
        if ($subscription && $subscription->asaas_customer_id) {
            $customerId = $subscription->asaas_customer_id;
        } else {
            try {
                $customerId = $this->getOrCreateCustomer($accountId);
            } catch (\Exception $e) {
                log_message('debug', "[PaymentService] Sync ignorado: Conta {$accountId} sem customer no gateway.");
                return;
            }
        }

        if (!$customerId) return;

        try {
            $pendingPayments = $this->activeGateway->getPendingPayments($customerId);
            
            if (empty($pendingPayments)) return;

            foreach ($pendingPayments as $p) {
                $gatewayPaymentId = $p['payment_id'];
                $gatewayStatus = strtoupper($p['status']);

                // Verificar se já existe no banco local por gateway_transaction_id
                $localTransaction = $this->transactionModel->where('gateway_transaction_id', $gatewayPaymentId)->first();

                if (!$localTransaction) {
                    // Tentar inferir o tipo pela descrição
                    $type = 'SUBSCRIPTION';
                    if (isset($p['description']) && (stripos($p['description'], 'Pro-rata') !== false || stripos($p['description'], 'Upgrade') !== false)) {
                        $type = 'UPGRADE_PRORATA';
                    }

                    $this->transactionModel->insert([
                        'account_id'      => $accountId,
                        'subscription_id' => $subscription ? $subscription->id : null,
                        'gateway'         => $this->activeGateway->getCode(),
                        'gateway_transaction_id' => $gatewayPaymentId,
                        'gateway_customer_id'    => $customerId,
                        'amount'          => $p['amount'],
                        'status'          => ($gatewayStatus === 'RECEIVED' || $gatewayStatus === 'CONFIRMED') ? 'SUCCESS' : 'PENDING',
                        'type'            => $type,
                        'payment_method'  => $p['billing_type'],
                        'invoice_url'     => $p['invoice_url'],
                        'description'     => $p['description'] ?? '',
                        'metadata'        => json_encode($p)
                    ]);

                    // Se já estiver pago, ativa a assinatura imediatamente
                    if (($gatewayStatus === 'RECEIVED' || $gatewayStatus === 'CONFIRMED') && $subscription) {
                        $this->activateSubscriptionByAsaasId($subscription->asaas_subscription_id, $gatewayPaymentId);
                    }
                } else {
                    // Já existe localmente. Se estiver PENDING aqui mas RECEBIDO lá, sincronize!
                    if ($localTransaction['status'] === 'PENDING' && ($gatewayStatus === 'RECEIVED' || $gatewayStatus === 'CONFIRMED')) {
                        log_message('notice', "[PaymentService] Sync: Transação {$gatewayPaymentId} detectada como PAGA no gateway. Atualizando local...");
                        $this->transactionModel->update($localTransaction['id'], ['status' => 'SUCCESS']);
                        
                        if ($subscription) {
                            $this->activateSubscriptionByAsaasId($subscription->asaas_subscription_id, $gatewayPaymentId);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            log_message('error', '[PaymentService] Erro ao sincronizar pagamentos: ' . $e->getMessage());
        }
    }
}
