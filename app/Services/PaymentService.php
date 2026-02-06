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
    protected $paymentProfileModel;
    protected $db;

    public function __construct()
    {
        $this->gatewayModel = new PaymentGatewayModel();
        $this->configModel = new PaymentGatewayConfigModel();
        $this->accountModel = Factories::models(AccountModel::class);
        $this->subscriptionModel = Factories::models(SubscriptionModel::class);
        $this->paymentProfileModel = model('App\Models\PaymentProfileModel'); // Ensure this model exists or use builder if new
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
        $db = \Config\Database::connect();
        $existingSub = $db->table('subscriptions')
            ->where('account_id', $accountId)
            ->where('asaas_customer_id IS NOT NULL')
            ->orderBy('id', 'DESC')
            ->get()
            ->getRow();

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
            
            if ($activeSub) {
                log_message('notice', '[PaymentService] Cliente ' . $customerId . ' já possui uma assinatura ativa no gateway: ' . $activeSub['subscription_id']);
                // Se já existe uma ativa, retornamos os dados dela em vez de criar outra
                return $activeSub;
            }

            log_message('debug', '[PaymentService] Criando assinatura no gateway para cliente ' . $customerId);
            $subscriptionData = $this->activeGateway->createSubscription($customerId, (string)$planId, $data);
            log_message('debug', '[PaymentService] Assinatura criada com sucesso no gateway: ' . $subscriptionData['subscription_id']);
        } catch (\Exception $e) {
            log_message('error', '[PaymentService] Erro ao criar assinatura no gateway: ' . $e->getMessage());
            throw new \Exception("Erro no gateway: " . $e->getMessage());
        }

        $subId = $subscriptionData['subscription_id'];
        $status = $subscriptionData['status'];
        
        $subscription = [
            'account_id' => $accountId,
            'plan_id' => $planId,
            'status' => strtoupper($status), 
            'data_inicio' => date('Y-m-d'),
            'data_fim' => date('Y-m-d', strtotime('+30 days')),
            'valor' => $finalAmount, // Valor com desconto
            'asaas_subscription_id' => $subId, 
            'asaas_customer_id' => $customerId,
            'payment_method' => $billingType,
            'next_billing_date' => $subscriptionData['next_billing_date']
        ];
        
        $this->subscriptionModel->insert($subscription);
        $localSubId = $this->subscriptionModel->getInsertID();

        // Log transaction
        $this->db->table('payment_transactions')->insert([
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
            'metadata' => json_encode([
                'gateway' => $this->activeGateway->getName(),
                'coupon' => $coupon ? $coupon->code : null,
                'original_amount' => $plan->preco_mensal,
                'invoice_url' => $subscriptionData['payment_url'] ?? null
            ])
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
     * Cancelar uma assinatura via Admin
     */
    public function cancelSubscription(int $subscriptionId)
    {
        $subscription = $this->subscriptionModel->find($subscriptionId);
        if (!$subscription || !$subscription->asaas_subscription_id) {
            throw new \Exception("Assinatura não encontrada ou sem vínculo com gateway.");
        }

        if (!$this->activeGateway) {
            throw new \Exception("Gateway de pagamento não configurado.");
        }

        try {
            if ($this->activeGateway->cancelSubscription($subscription->asaas_subscription_id)) {
                $this->subscriptionModel->update($subscriptionId, ['status' => 'CANCELLED']);
                return true;
            }
            return false;
        } catch (\Exception $e) {
            log_message('error', 'Erro ao cancelar assinatura: ' . $e->getMessage());
            throw new \Exception("Erro ao cancelar no gateway: " . $e->getMessage());
        }
    }

    /**
     * Cancelar um pagamento (charge) no gateway
     */
        public function cancelPayment(int $subscriptionId)
    {
        $transaction = $this->db->table('payment_transactions')
            ->where('subscription_id', $subscriptionId)
            ->where('status', 'PENDING')
            ->orderBy('id', 'DESC')
            ->get()
            ->getRow();

        log_message('debug', "CancelPayment: SubscriptionId=$subscriptionId. Local Trx Found? " . ($transaction ? $transaction->id : 'NO'));


        // [FIX] Orphan Check: Se não achar na assinatura atual, busca upgrades pendentes na CONTA
        // Isso resolve casos onde o upgrade ficou vinculado a uma assinatura antiga (bug de legado)
        if (!$transaction) {
             $sub = $this->subscriptionModel->find($subscriptionId);
             if ($sub) {
                  $builder = $this->db->table('payment_transactions');
                  $builder->where('account_id', $sub->account_id);
                  $builder->where('status', 'PENDING');
                  // [FIX] Removed specific type check to catch RECURRING_CHARGE too
                  // ->where('type', 'UPGRADE_PRORATA') 
                  $builder->orderBy('id', 'DESC');
                  $transaction = $builder->get()->getRow();
                 
                 if ($transaction) {
                     log_message('debug', "[PaymentService] Found ORPHAN upgrade transaction {$transaction->id} for account {$sub->account_id} while cancelling sub {$subscriptionId}");
                 }
             }
        }

        if (!$transaction || !$transaction->gateway_transaction_id) {
            // Se não tem ID no gateway, apenas retornamos true para seguir com cancelamento local
            return true;
        }

        if (!$this->activeGateway) {
            throw new \Exception("Gateway de pagamento não configurado.");
        }

        $success = true;

        try {
            // 1. Cancelar Transação Pendente se existir
            if ($transaction && $transaction->gateway_transaction_id) {
                // Garantir que o gateway está configurado corretamente
                if ($transaction->gateway !== $this->activeGateway->getCode()) {
                    $this->setGateway($transaction->gateway);
                }

                try {
                    log_message('debug', "CancelPayment: Calling Gateway {$this->activeGateway->getCode()} to cancel {$transaction->gateway_transaction_id}");
                    if ($this->activeGateway->cancelPayment($transaction->gateway_transaction_id)) {
                        $this->db->table('payment_transactions')
                            ->where('id', $transaction->id)
                            ->update(['status' => 'CANCELLED']);
                    }
                } catch (\Exception $e) {
                    log_message('error', 'Erro ao cancelar transação no gateway: ' . $e->getMessage());
                    $success = false;
                }
            }

                                                            // 2. Se for uma fatura de UPGRADE (pró-rata), Tentar Reverter ou Cancelar Tudo!
            if ($transaction && $transaction->type === "UPGRADE_PRORATA") {
                 $metadata = json_decode($transaction->metadata ?? "{}", true);
                 
                 // Fallback: Tentar extrair do description se não houver metadata
                 if (!isset($metadata["old_plan_id"]) || !isset($metadata["old_price"])) {
                     $desc = $transaction->description ?? ($metadata["description"] ?? "");
                     if (preg_match("/Upgrade:\s*(.*?)\s*->/", $desc, $matches)) {
                         $oldPlanName = trim($matches[1]);
                         $planModel = model("App\Models\PlanModel");
                         $oldPlan = $planModel->where("nome", $oldPlanName)->first();
                         if ($oldPlan) {
                             $metadata["old_plan_id"] = $oldPlan->id;
                             $metadata["old_price"] = $oldPlan->preco_mensal;
                             log_message("debug", "[PaymentService] Fallback: Plano anterior identificado via descrição: {$oldPlan->nome}");
                         }
                     }
                 }

                 if (isset($metadata["old_plan_id"])) {
                     log_message("debug", "[PaymentService] Revertendo plano para {$metadata["old_plan_id"]} após cancelamento de upgrade.");
                     
                     // Reverter Gateway
                     $sub = $this->subscriptionModel->find($subscriptionId);
                     if ($sub && $sub->asaas_subscription_id) {
                         $this->activeGateway->updateSubscription($sub->asaas_subscription_id, [
                             'amount' => (float)$metadata['old_price'],
                             'description' => "Assinatura (Revertida - Upgrade cancelado)"
                         ]);
                     }
                     
                     // Reverter Local
                     $this->subscriptionModel->update($subscriptionId, ['plan_id' => $metadata['old_plan_id']]);
                     
                     log_message("debug", "[PaymentService] Cancelando cobrança de upgrade (pró-rata) para assinatura {$subscriptionId}");
                     return $success;
                 } else {
                     log_message("warning", "[PaymentService] CRÍTICO: Não foi possível identificar plano anterior. Cancelando TUDO (Assinatura e Cobranças).");
                     // FORÇAR Cancelamento Local aqui, pois o Controller pode ignorar se estiver ATIVA
                     $this->subscriptionModel->update($subscriptionId, ['status' => 'CANCELLED']);
                 }
            }

            // 3. Se for uma Assinatura Nativa, cancelar a assinatura também
            $sub = $this->subscriptionModel->find($subscriptionId);
            if ($sub && $sub->asaas_subscription_id) {
                try {
                    $this->activeGateway->cancelSubscription($sub->asaas_subscription_id);
                } catch (\Exception $e) {
                    log_message('error', 'Erro ao cancelar assinatura no gateway: ' . $e->getMessage());
                    $success = false;
                }
            }

            return $success;
        } catch (\Exception $e) {
            log_message('error', 'Erro fatal no fluxo de cancelamento: ' . $e->getMessage());
            return false;
        }
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
                        $this->db->table('payment_transactions')->insert([
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
                            'metadata'        => json_encode([
                                'description'  => $data['description'],
                                'invoice_url'  => $paymentData['payment_url'],
                                'old_plan_id'  => $oldPlan->id,
                                'old_price'    => $oldPlan->preco_mensal
                            ])
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
        $this->db->table('payment_transactions')->insert([
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
            'metadata' => json_encode([
                'gateway' => $this->activeGateway->getName(),
                'coupon' => $coupon ? $coupon->code : null,
                'invoice_url' => $paymentData['payment_url']
            ])
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
            $remoteSub = $this->activeGateway->getSubscription($subscription->asaas_subscription_id);
            
            // Map remote status to local status if needed
            // Asaas: ACTIVE, EXPIRED, INACTIVE, etc.
            $remoteStatus = strtoupper($remoteSub['status']);
            $isDeleted = $remoteSub['deleted'] ?? false;

            $localStatus = strtoupper($subscription->status);
            
            $newStatus = null;

            if ($isDeleted && !in_array($localStatus, ['CANCELLED', 'DELETED'])) {
                $newStatus = 'CANCELLED';
            } elseif ($remoteStatus !== $localStatus) {
                // Ignore small differences if necessary, but generally we want to sync.
                // Exceptions: Local might be 'CANCELLED_POR_TROCA' which is internal, but if Asaas says ACTIVE, we might have a problem.
                // For now, let's trust Remote for critical states (ACTIVE vs NON-ACTIVE)
                
                // If remote is EXPIRED/INACTIVE and we think it's ACTIVE -> fix it
                if (in_array($remoteStatus, ['EXPIRED', 'INACTIVE']) && $localStatus === 'ACTIVE') {
                    $newStatus = ($remoteStatus === 'EXPIRED') ? 'EXPIRED' : 'CANCELLED';
                }
                
                // If remote is ACTIVE and we think it's CANCELLED -> Maybe reactivate? 
                // Careful with this one. Better to only auto-cancel for now to prevent access leaks.
                if ($remoteStatus === 'ACTIVE' && in_array($localStatus, ['CANCELLED', 'SUSPENDED', 'EXPIRED'])) {
                    $newStatus = 'ACTIVE';
                }
            }
            
            if ($newStatus) {
                log_message('notice', "[PaymentService] Double Verification: Syncing Sub #{$subscriptionId} from {$localStatus} to {$newStatus}");
                $this->subscriptionModel->update($subscriptionId, ['status' => $newStatus]);
                
                // If we flipped to inactive state, ensure account is updated too
                if (in_array($newStatus, ['CANCELLED', 'EXPIRED', 'INACTIVE'])) {
                     $this->accountModel->update($subscription->account_id, ['status' => 'INACTIVE']);
                }
                
                return true;
            }

        } catch (\Exception $e) {
            log_message('error', "[PaymentService] Error syncing subscription status: " . $e->getMessage());
        }

        return false;
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
                // Verificar se já existe no banco local por gateway_transaction_id
                $exists = $this->db->table('payment_transactions')
                                  ->where('gateway_transaction_id', $p['payment_id'])
                                  ->countAllResults() > 0;

                if (!$exists) {
                    // Tentar inferir o tipo pela descrição
                    $type = 'SUBSCRIPTION';
                    if (isset($p['description']) && (stripos($p['description'], 'Pro-rata') !== false || stripos($p['description'], 'Upgrade') !== false)) {
                        $type = 'UPGRADE_PRORATA';
                    }

                    $this->db->table('payment_transactions')->insert([
                        'account_id'      => $accountId,
                        'subscription_id' => $subscription ? $subscription->id : null,
                        'gateway'         => $this->activeGateway->getCode(),
                        'gateway_transaction_id' => $p['payment_id'],
                        'gateway_customer_id'    => $customerId,
                        'amount'          => $p['amount'],
                        'status'          => 'PENDING',
                        'type'            => $type,
                        'payment_method'  => $p['billing_type'],
                        'invoice_url'     => $p['invoice_url'],
                        'description'     => $p['description'] ?? '',
                        'metadata'        => json_encode([
                            'description' => $p['description'] ?? '',
                            'invoice_url' => $p['invoice_url'],
                            'dueDate'     => $p['dueDate']
                        ]),
                        'created_at'      => date('Y-m-d H:i:s'),
                        'updated_at'      => date('Y-m-d H:i:s')
                    ]);
                    
                    log_message('notice', "[PaymentService] Sincronizado pagamento pendente: {$p['payment_id']} para conta {$accountId}");
                } else {
                    // Se já existe, garante que o status esteja atualizado no banco se vier do sync (opcional)
                    // Mas aqui focamos em PENDING forçados pelo sync
                }
            }
        } catch (\Exception $e) {
            log_message('error', '[PaymentService] Erro ao sincronizar pagamentos: ' . $e->getMessage());
        }
    }
}
