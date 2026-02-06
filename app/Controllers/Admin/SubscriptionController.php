<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class SubscriptionController extends BaseController
{
    public function index()
    {
        $user = auth()->user();
        $isAdmin = $user->inGroup('superadmin', 'admin');
        
        // CRITICAL: Non-admin MUST have account_id. No fallback to admin data!
        if (!$isAdmin && !$user->account_id) {
            return redirect()->to('admin')->with('error', 'Sua conta está com problema. Contate o suporte ou crie uma nova conta.');
        }
        
        $accountId = $user->account_id ?? 1; // Only admins can have null account_id

        // Sincronizar pagamentos pendentes com o gateway (Garante que cobranças órfãs apareçam)
        try {
            $paymentService = new \App\Services\PaymentService();
            $paymentService->syncPendingPayments($accountId);
            
            // [Double Verification] Se encontrar assinatura ativa no banco, valida no gateway
            $subscriptionModel = model('App\Models\SubscriptionModel');
            $activeSubCheck = $subscriptionModel->where('account_id', $accountId)->where('status', 'ACTIVE')->first();
            
            if ($activeSubCheck) {
                 $paymentService->syncSubscriptionStatus($activeSubCheck->id);
            }
            
        } catch (\Exception $e) {
            log_message('error', '[SubscriptionController] Erro ao sincronizar pagamentos/status: ' . $e->getMessage());
        }

        $subscriptionModel = model('App\Models\SubscriptionModel');
        $planModel = model('App\Models\PlanModel');
        $propertyModel = model('App\Models\PropertyModel');

        $subscription = $subscriptionModel->where('account_id', $accountId)
                                         ->where('status', 'ACTIVE')
                                         ->orderBy('created_at', 'DESC')
                                         ->first();
        
        // Check for pending subscription (AWAITING_PAYMENT or PENDING)
        $pendingSubscription = $subscriptionModel->groupStart()
                ->where('status', 'PENDING')
                ->orWhere('status', 'AWAITING_PAYMENT')
            ->groupEnd()
            ->where('account_id', $accountId)
            ->orderBy('created_at', 'DESC')
            ->first();

        $plan = null;
        if ($subscription) {
            $plan = $planModel->find($subscription->plan_id);
        }
        
        $pendingPlan = null;
        if ($pendingSubscription) {
            $pendingPlan = $planModel->find($pendingSubscription->plan_id);
        }

        // Estatísticas de uso
        $usage = [
            'active_properties' => $propertyModel->where('account_id', $accountId)->where('status', 'ACTIVE')->countAllResults(),
            'limit' => $plan ? $plan->limite_imoveis_ativos : 0,
            'is_unlimited' => $plan ? ($plan->limite_imoveis_ativos === null) : false
        ];

        // Todos os planos para "Upgrade" (Simulação)
        $allPlans = $planModel->where('ativo', true)->orderBy('preco_mensal', 'ASC')->findAll();

        // Fetch Pending Transaction Details (Pix/Boleto info)
        $lastTransaction = null;
        $transactionModel = model('App\Models\PaymentTransactionModel');
        $lastTransaction = $transactionModel->getLastPendingTransactionByAccount($accountId);
        
        if ($lastTransaction) {
            $lastTransaction = (object) $lastTransaction;
        }

        // Se temos uma transação pendente mas não temos pendingSubscription, 
        // tentamos vincular para a view mostrar o alerta corretamente
        if ($lastTransaction && !$pendingSubscription) {
             // Only treat as subscription issue if NOT a Turbo/One-off type
             if ($lastTransaction->type !== 'TURBO') {
                 $pendingSubscription = $subscription; // Use active sub as reference
                 $pendingPlan = $plan;
             }
        }

        if ($lastTransaction) {
            // Se a transação é do tipo UPGRADE_PRORATA, tratamos a mensagem diferente
            if (isset($lastTransaction->type) && $lastTransaction->type === 'UPGRADE_PRORATA') {
                 // Fake pending behavior but with better info
                 $pendingSubscription = $subscription;
                 $pendingPlan = $plan;
                 $pendingSubscription->custom_pending_msg = "Você possui uma cobrança proporcional (Pró-rata) referente ao upgrade para o plano <strong>" . esc($plan->nome) . "</strong> aguardando pagamento.";
            }
        }

        return view('admin/subscription/index', [
            'subscription' => $subscription,
            'pendingSubscription' => $pendingSubscription,
            'lastTransaction' => $lastTransaction, // Pass transaction to view
            'plan' => $plan,
            'pendingPlan' => $pendingPlan,
            'usage' => $usage,
            'allPlans' => $allPlans
        ]);
    }

    public function previewUpgrade($planId)
    {
        $user = auth()->user();
        $accountId = $user->account_id ?? 1;

        $planModel = model('App\Models\PlanModel');
        $newPlan = $planModel->find($planId);

        if (!$newPlan) {
            return $this->response->setJSON(['error' => 'Plano não encontrado.'])->setStatusCode(404);
        }

        $subscriptionModel = model('App\Models\SubscriptionModel');
        $activeSub = $subscriptionModel->where('account_id', $accountId)
                                      ->where('status', 'ACTIVE')
                                      ->first();

        if (!$activeSub) {
            return $this->response->setJSON([
                'is_upgrade' => false,
                'pro_rata' => 0,
                'new_price' => (float)$newPlan->preco_mensal,
                'message' => 'Nova assinatura.'
            ]);
        }

        $oldPlan = $planModel->find($activeSub->plan_id);
        $isUpgrade = $newPlan->preco_mensal > $oldPlan->preco_mensal;
        $isDowngrade = $newPlan->preco_mensal < $oldPlan->preco_mensal;

        $paymentService = new \App\Services\PaymentService();
        
        $proRata = 0;
        if ($isUpgrade) {
            $calc = $paymentService->previewUpgradeProRata($accountId, (int)$planId);
            $proRata = $calc['value'];
        }

        return $this->response->setJSON([
            'is_upgrade' => $isUpgrade,
            'is_downgrade' => $isDowngrade,
            'pro_rata' => $proRata,
            'old_plan_name' => $oldPlan->nome,
            'new_plan_name' => $newPlan->nome,
            'new_price' => (float)$newPlan->preco_mensal,
            'formatted_pro_rata' => number_format($proRata, 2, ',', '.')
        ]);
    }

    public function upgrade($planId)
    {
        $user = auth()->user();
        $isAdmin = $user->inGroup('superadmin', 'admin');
        
        // Security Check
        if (!$isAdmin && !$user->account_id) {
            return redirect()->back()->with('error', 'Conta inválida.');
        }
        $accountId = $user->account_id ?? 1;

        // 1. Carregar Plano Alvo
        $planModel = model('App\Models\PlanModel');
        $targetPlan = $planModel->find($planId);

        if (!$targetPlan) {
            return redirect()->back()->with('error', 'Plano não encontrado.');
        }

        // 2. Verificar Limites (Regra de Downgrade)
        $propertyModel = model('App\Models\PropertyModel');
        $activeProperties = $propertyModel->where('account_id', $accountId)->where('status', 'ACTIVE')->countAllResults();

        // Se o plano tem limite e o usuário tem mais imóveis que o limite
        if ($targetPlan->limite_imoveis_ativos !== null && $activeProperties > $targetPlan->limite_imoveis_ativos) {
            $diff = $activeProperties - $targetPlan->limite_imoveis_ativos;
            return redirect()->back()->with('error', "Não é possível mudar para este plano. Você tem {$activeProperties} imóveis ativos, mas o plano {$targetPlan->nome} permite apenas {$targetPlan->limite_imoveis_ativos}.");
        }

        // 2.1 Verificar Destaques Ativos
        // 2.1 Verificar Destaques Ativos (Do Plano)
        if ($targetPlan->destaques_mensais !== null) {
            // [FIX] Paid Highlights (Turbo) are separate from Plan Highlights (is_destaque).
            // Only count properties that are using the plan's highlight slots.
            $propertyModel = model('App\Models\PropertyModel');
            $activeHighlights = $propertyModel
                ->where('account_id', $accountId)
                ->where('is_destaque', true)
                ->where('status', 'ACTIVE') // Only count active properties
                ->countAllResults();

            if ($activeHighlights > $targetPlan->destaques_mensais) {
                 return redirect()->back()->with('error', "Não é possível mudar para este plano. Você tem {$activeHighlights} destaques ativos, mas o plano {$targetPlan->nome} permite apenas {$targetPlan->destaques_mensais}. Aguarde o término dos destaques ou cancele-os.");
            }
        }
        
        // 3. Se for GRATUITO (R$ 0,00), troca direto!
        if ($targetPlan->preco_mensal <= 0) {
            $subscriptionModel = model('App\Models\SubscriptionModel');
            
            $currentSub = $subscriptionModel->where('account_id', $accountId)->where('status', 'ACTIVE')->first();
            if ($currentSub) {
                if ($currentSub->plan_id == $targetPlan->id) {
                    return redirect()->back()->with('message', 'Você já está neste plano.');
                }
                $currentSub->status = 'CANCELADA_POR_TROCA'; 
                $subscriptionModel->save($currentSub);
            }

            $subscriptionModel->insert([
                'account_id' => $accountId,
                'plan_id'    => $targetPlan->id,
                'status'     => 'ACTIVE',
                'data_inicio'=> date('Y-m-d'),
                'preco_pago' => 0.00,
                'payment_method' => 'FREE'
            ]);

            return redirect()->to('admin/subscription')->with('message', "Plano alterado para {$targetPlan->nome} com sucesso!");
        }

        // 4. Se for PAGO e já tem assinatura ativa, faz o Upgrade/Downgrade via Service
        $subscriptionModel = model('App\Models\SubscriptionModel');
        $activeSub = $subscriptionModel->where('account_id', $accountId)
                                      ->where('status', 'ACTIVE')
                                      ->first();

        if ($activeSub) {
            $currentPlan = $planModel->find($activeSub->plan_id);
            
            // Bloqueio de Downgrade: Se o plano alvo é mais barato que o atual
            if ($targetPlan->preco_mensal < $currentPlan->preco_mensal) {
                return redirect()->back()->with('error', "Downgrade bloqueado. Para mudar para um plano inferior, você deve primeiro cancelar sua assinatura atual e aguardar o término do período ou contratar o novo plano após o cancelamento.");
            }

            try {
                $paymentService = new \App\Services\PaymentService();
                $paymentService->changeSubscriptionPlan($accountId, (int)$planId);
                return redirect()->to('admin/subscription')->with('message', "Plano alterado para {$targetPlan->nome} com sucesso!");
            } catch (\Exception $e) {
                return redirect()->back()->with('error', "Erro ao alterar plano: " . $e->getMessage());
            }
        }

        // 5. Se não tem assinatura ativa, manda pro Checkout normal
        return redirect()->to("checkout/plan/{$planId}");
    }
    public function invoices()
    {
        $user = auth()->user();
        $isAdmin = $user->inGroup('superadmin', 'admin');
        
        if (!$isAdmin && !$user->account_id) {
            return redirect()->to('admin')->with('error', 'Conta inválida.');
        }
        $accountId = $user->account_id ?? 1;

        $db = \Config\Database::connect();
        
        // Fetch transactions with plan info if possible (joining subscriptions?)
        // Or simpler: just list payments
        $transactions = $db->table('payment_transactions')
            ->select('payment_transactions.*, subscriptions.plan_id, plans.nome as plan_name')
            ->join('subscriptions', 'subscriptions.id = payment_transactions.subscription_id', 'left')
            ->join('plans', 'plans.id = subscriptions.plan_id', 'left')
            ->where('payment_transactions.account_id', $accountId)
            ->orderBy('payment_transactions.created_at', 'DESC')
            ->get()
            ->getResult();

        return view('admin/subscription/invoices', [
            'transactions' => $transactions
        ]);
    }

    public function cancel($id)
    {
        $user = auth()->user();
        $accountId = $user->account_id;

        if (!$accountId) {
            return redirect()->back()->with('error', 'Conta não identificada.');
        }

        $subscriptionModel = model('App\Models\SubscriptionModel');
        $subscription = $subscriptionModel->where('account_id', $accountId)->find($id);

        if (!$subscription) {
            return redirect()->back()->with('error', 'Pedido não encontrado.');
        }

        if (!in_array(strtoupper($subscription->status), ['PENDING', 'AWAITING_PAYMENT', 'ACTIVE'])) {
            return redirect()->back()->with('error', 'Apenas pedidos pendentes podem ser cancelados por aqui.');
        }

        // Tenta cancelar no gateway primeiro (Pix/Boleto avulso)
        try {
            $paymentService = new \App\Services\PaymentService();
            $paymentService->cancelPayment((int)$id);
        } catch (\Exception $e) {
            log_message('error', 'Erro ao cancelar pagamento no gateway: ' . $e->getMessage());
            // Seguimos com o cancelamento local mesmo se falhar no gateway para não travar o cliente
        }

        // Update local status ONLY if it was a new subscription/pending
        // If it's an ATIVA subscription, it means we only cancelled a pending UPGRADE charge
        if (in_array(strtoupper($subscription->status), ['PENDING', 'AWAITING_PAYMENT'])) {
            $subscriptionModel->update($id, ['status' => 'CANCELLED']);
        }

        return redirect()->to('admin/subscription')->with('message', 'Pedido cancelado com sucesso.');
    }
}
