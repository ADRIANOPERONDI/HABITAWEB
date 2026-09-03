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

        $subscriptionModel = model('App\Models\SubscriptionModel');
        $planModel = model('App\Models\PlanModel');
        $propertyModel = model('App\Models\PropertyModel');

        // Sincronizar pagamentos pendentes com o gateway (Garante que cobranças órfãs apareçam).
        // Debounce de 120s por conta: sem isso, toda visita a esta página faz N
        // chamadas HTTP síncronas ao Asaas (1 + 1 por assinatura não-terminal),
        // sem limite - sob muitos admins/contas simultâneos isso vira pressão
        // descontrolada no gateway. spark asaas:sync já reconcilia tudo via cron;
        // este bloco só existe para dar frescor entre execuções do cron, então
        // não precisa rodar em toda requisição.
        $syncStaleKey = "subscription_sync_stale_{$accountId}";
        if (cache()->get($syncStaleKey) === null) {
            try {
                $paymentService = new \App\Services\PaymentService();
                $paymentService->syncPendingPayments($accountId);

                // [Double Verification] Busca assinaturas para sincronizar (inclui ACTIVE para checar se expirou ou se há faturas novas)
                $staleSubs = $subscriptionModel->where('account_id', $accountId)
                                             ->whereIn('status', ['ACTIVE', 'SUSPENDED', 'PENDING', 'AWAITING_PAYMENT'])
                                             ->findAll();

                foreach ($staleSubs as $subToSync) {
                     $paymentService->syncSubscriptionStatus($subToSync->id);
                }

                cache()->save($syncStaleKey, true, 120);
            } catch (\Exception $e) {
                log_message('error', '[SubscriptionController] Erro ao sincronizar pagamentos/status: ' . $e->getMessage());
            }
        }

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
        $allPlans = $planModel->comercializaveis();

        // Fetch Pending Transaction Details (Pix/Boleto info)
        $lastTransaction = null;
        $transactionModel = model('App\Models\PaymentTransactionModel');
        $lastTransaction = $transactionModel->getLastPendingTransactionByAccount($accountId);
        
        if ($lastTransaction) {
            $lastTransaction = (object) $lastTransaction;
            
            // SECURITY CHECK: Se já temos assinatura ATIVA para o MESMO PLANO da transação pendente,
            // essa transação é lixo/duplicata e deve ser ignorada na visualização (e limpar se possível)
            if ($subscription && $plan && isset($lastTransaction->subscription_id)) {
                 // Se a transação pertence a uma sub que não é a ativa, ou se é pra o mesmo plano
                 // e já estamos ativos, vamos ocultar o alerta.
                 if ($subscription->plan_id == $plan->id && !isset($lastTransaction->type)) {
                     // Log details to help debug if it persists
                     log_message('debug', "[SubscriptionController] Ocultando transação pendente órfã #{$lastTransaction->id} pois plano já está ativo.");
                     $lastTransaction = null;
                 }
            }
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

        return view('Admin/subscription/index', [
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
        // exposure_weight, não preco_mensal: durante a rampa (Fase 6) todo
        // plano pode custar o mesmo (inclusive R$0 no mesmo mês), e o preço
        // bruto deixa de distinguir tier. exposure_weight é a ordem
        // explícita entre planos, e não muda com a rampa.
        $isUpgrade = $newPlan->exposure_weight > $oldPlan->exposure_weight;
        $isDowngrade = $newPlan->exposure_weight < $oldPlan->exposure_weight;

        $paymentService = new \App\Services\PaymentService();
        $rampService = new \App\Services\LaunchRampService($paymentService);
        $billingCycle = (string) ($activeSub->billing_cycle ?? 'MONTHLY');

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
            'new_price' => $rampService->amountFor($newPlan, $billingCycle, $activeSub),
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
        $billingType = strtoupper((string) $this->request->getPost('billing_type'));

        if (!in_array($billingType, ['PIX', 'BOLETO', 'CREDIT_CARD'], true)) {
            return redirect()->back()->with('error', 'Selecione uma forma de pagamento válida.');
        }

        // 1. Carregar Plano Alvo — precisa estar ativo E no catálogo
        // comercial (preco_mensal > 0). Sem isto, um POST direto alcançava
        // um plano legado/desativado ou de teste só por saber o id dele.
        $planModel = model('App\Models\PlanModel');
        $targetPlan = $planModel->find($planId);

        if (!$targetPlan || !$targetPlan->ativo || (float) $targetPlan->preco_mensal <= 0) {
            return redirect()->back()->with('error', 'Plano não encontrado ou indisponível.');
        }

        // 2. Verificar Limites (Regra de Downgrade)
        $propertyModel = model('App\Models\PropertyModel');
        $activeProperties = $propertyModel->where('account_id', $accountId)->where('status', 'ACTIVE')->countAllResults();

        // Se o plano tem limite e o usuário tem mais imóveis que o limite
        if ($targetPlan->limite_imoveis_ativos !== null && $activeProperties > $targetPlan->limite_imoveis_ativos) {
            $diff = $activeProperties - $targetPlan->limite_imoveis_ativos;
            return redirect()->back()->with('error', "Não é possível mudar para este plano. Você tem {$activeProperties} imóveis ativos, mas o plano {$targetPlan->nome} permite apenas {$targetPlan->limite_imoveis_ativos}.");
        }

        // Não há mais trava de destaque na troca de plano.
        //
        // A que existia aqui contava imóveis com `is_destaque` contra
        // `destaques_mensais`, cruzando dois conceitos que nunca foram o mesmo:
        // `is_destaque` é selo editorial da Habitaweb e `destaques_mensais`
        // nunca governou concessão nenhuma (quem governa é limite_turbo_mensal).
        // A turbinada, por sua vez, é comprada por prazo e já paga — bloquear a
        // troca de plano por causa dela puniria o cliente por ter comprado.
        // Quem passa a controlar quantas turbinadas o plano concede por mês é o
        // TurboService, na concessão, não na troca.


        $subscriptionModel = model('App\Models\SubscriptionModel');
        $activeSub = $subscriptionModel->where('account_id', $accountId)
                                      ->where('status', 'ACTIVE')
                                      ->first();

        // 3. Bloqueio de Downgrade — ANTES do desvio gratuito abaixo.
        // Compara exposure_weight (ordem de tier explícita), não
        // preco_mensal: durante a rampa (Fase 6) todo plano pode custar o
        // MESMO valor no mesmo mês (inclusive R$0), e o preço bruto para de
        // distinguir tier. Ficar depois do desvio gratuito deixaria uma
        // conta em mês 0% trocar de Diamante pra Prata sem passar pela
        // trava — os dois "custam" R$0 naquele mês.
        if ($activeSub) {
            $currentPlan = $planModel->find($activeSub->plan_id);

            if ($currentPlan && $targetPlan->exposure_weight < $currentPlan->exposure_weight) {
                return redirect()->back()->with('error', "Downgrade bloqueado. Para mudar para um plano inferior, você deve primeiro cancelar sua assinatura atual e aguardar o término do período ou contratar o novo plano após o cancelamento.");
            }
        }

        // 4. Se for GRATUITO — plano estaticamente gratuito (preco_mensal <= 0)
        // OU o valor EFETIVO desta assinatura, com o desconto de rampa
        // (Fase 6) aplicado, for zero — troca direto, sem gateway.
        // Conta sem rampa (ramp_started_at nulo, o caso de toda conta hoje)
        // sempre cai no comportamento de antes: só preco_mensal <= 0 importa.
        $paymentService = new \App\Services\PaymentService();
        $rampService = new \App\Services\LaunchRampService($paymentService);
        $billingCycle = (string) ($activeSub->billing_cycle ?? 'MONTHLY');
        $effectiveAmount = $rampService->amountFor($targetPlan, $billingCycle, $activeSub);

        if ($effectiveAmount <= 0) {
            if ($activeSub && (int) $activeSub->plan_id === (int) $targetPlan->id) {
                return redirect()->back()->with('message', 'Você já está neste plano.');
            }

            // Continua o relógio da rampa da assinatura anterior (se havia
            // uma) em vez de resetar para hoje — trocar de plano não deveria
            // dar mais 6 meses grátis de novo.
            $paymentService->createFreeLocalSubscription(
                $accountId,
                $targetPlan,
                $billingCycle,
                $activeSub->ramp_started_at ?? null
            );

            return redirect()->to('admin/subscription')->with('message', "Plano alterado para {$targetPlan->nome} com sucesso!");
        }

        // 5. Se for PAGO e já tem assinatura ativa, faz o Upgrade/Downgrade via Service
        // (o bloqueio de downgrade já foi conferido no passo 3, antes do desvio gratuito)
        if ($activeSub) {
            try {
                $result = $paymentService->changeSubscriptionPlan($accountId, (int)$planId, $billingType);

                if ($billingType === 'CREDIT_CARD' && !empty($result['payment_url'])) {
                    return redirect()->to($result['payment_url']);
                }

                return redirect()->to('admin/subscription')->with('message', "Plano alterado para {$targetPlan->nome} com sucesso!");
            } catch (\Exception $e) {
                return redirect()->back()->with('error', "Erro ao alterar plano: " . $e->getMessage());
            }
        }

        // 6. Se não tem assinatura ativa, manda pro Checkout normal
        return redirect()->to("checkout/plan/{$planId}");
    }

    public function changePaymentMethod($transactionId)
    {
        $user = auth()->user();
        $isAdmin = $user->inGroup('superadmin', 'admin');

        if (!$isAdmin && !$user->account_id) {
            return redirect()->back()->with('error', 'Conta inválida.');
        }

        $accountId = $user->account_id ?? 1;
        $billingType = strtoupper((string) $this->request->getPost('billing_type'));

        if (!in_array($billingType, ['PIX', 'BOLETO', 'CREDIT_CARD'], true)) {
            return redirect()->back()->with('error', 'Selecione uma forma de pagamento válida.');
        }

        try {
            $paymentService = new \App\Services\PaymentService();
            $result = $paymentService->regeneratePendingPayment($accountId, (int) $transactionId, $billingType);

            if ($billingType === 'CREDIT_CARD' && !empty($result['payment_url'])) {
                return redirect()->to($result['payment_url']);
            }

            return redirect()->to('admin/subscription')->with('message', 'Forma de pagamento atualizada. A nova fatura já está disponível.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Erro ao alterar forma de pagamento: ' . $e->getMessage());
        }
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

        return view('Admin/subscription/invoices', [
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

        // Tenta cancelar no gateway e localmente de forma atômica
        try {
            $paymentService = new \App\Services\PaymentService();
            $paymentService->cancelSubscription((int)$id);
            return redirect()->to('admin/subscription')->with('message', 'Pedido e cobranças canceladas com sucesso.');
        } catch (\Exception $e) {
            log_message('error', 'Erro fatal ao cancelar assinatura: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Erro ao processar cancelamento: ' . $e->getMessage());
        }
    }
}
