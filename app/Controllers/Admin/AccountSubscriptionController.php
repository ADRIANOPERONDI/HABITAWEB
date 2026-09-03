<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\PaymentService;

class AccountSubscriptionController extends BaseController
{
    protected $paymentService;
    protected $subscriptionModel;
    protected $accountModel;

    public function __construct()
    {
        $this->paymentService = new PaymentService();
        $this->subscriptionModel = model('App\Models\SubscriptionModel');
        $this->accountModel = model('App\Models\AccountModel');
    }

    /**
     * Obter detalhes da assinatura para o modal/aba
     */
    public function show($accountId)
    {
        $account = $this->accountModel->find($accountId);
        if (!$account) {
            return $this->response->setJSON(['error' => 'Conta não encontrada.'])->setStatusCode(404);
        }

        $subscription = $this->subscriptionModel->where('account_id', $accountId)->orderBy('id', 'DESC')->first();
        $plans = model('App\Models\PlanModel')->comercializaveis();
        
        $transactionModel = model('App\Models\PaymentTransactionModel');
        $pendingTransactions = $transactionModel->where('account_id', $accountId)
                                                ->whereIn('status', ['PENDING', 'AWAITING_PAYMENT'])
                                                ->orderBy('created_at', 'ASC')
                                                ->findAll();

        // Rampa de lançamento (Fase 6) — o operador precisa saber se e
        // quando esta conta vira cobrança real, e se falta ele agir (a
        // virada 0%→X% não é automática, ver ApplyLaunchRamp).
        $percentHoje = null;
        $valorHoje = null;
        $pendingRampCharge = false;

        if ($subscription && $subscription->ramp_started_at) {
            $rampService = new \App\Services\LaunchRampService($this->paymentService);
            $percentHoje = $rampService->percentFor($subscription);

            $plan = model('App\Models\PlanModel')->find($subscription->plan_id);
            if ($plan) {
                $billingCycle = (string) ($subscription->billing_cycle ?? 'MONTHLY');
                $valorHoje = $rampService->amountFor($plan, $billingCycle, $subscription);
            }

            $pendingRampCharge = $subscription->payment_method === 'FREE'
                && $percentHoje > 0
                && empty($subscription->asaas_subscription_id);
        }

        return $this->response->setJSON([
            'subscription' => $subscription,
            'plans' => $plans,
            'pendingTransactions' => $pendingTransactions,
            'gateway' => $this->paymentService->getActiveGatewayName(),
            'ramp_started_at' => $subscription->ramp_started_at ?? null,
            'ramp_percent_atual' => $subscription->ramp_percent_atual ?? null,
            'percent_hoje' => $percentHoje,
            'valor_hoje' => $valorHoje,
            'pendingRampCharge' => $pendingRampCharge,
        ]);
    }

    /**
     * POST: operador liga a cobrança real de uma assinatura que estava em
     * modo FREE (rampa, Fase 6) e passou pra uma faixa paga — a virada
     * 0%→X% que `ApplyLaunchRamp` só registra em audit_log e não
     * automatiza: é a primeira cobrança real da conta, o momento
     * comercialmente mais frágil do modelo, e o cliente nunca escolheu
     * forma de pagamento pra recorrência.
     */
    public function startGateway($accountId)
    {
        if (!auth()->user()->inGroup('superadmin')) {
            return $this->response->setJSON(['error' => 'Acesso negado.'])->setStatusCode(403);
        }

        $billingType = strtoupper((string) $this->request->getPost('billing_type'));

        $subscription = $this->subscriptionModel->where('account_id', $accountId)
            ->where('status', 'ACTIVE')
            ->orderBy('id', 'DESC')
            ->first();

        if (!$subscription) {
            return $this->response->setJSON(['error' => 'Assinatura ativa não encontrada.'])->setStatusCode(404);
        }

        try {
            $result = $this->paymentService->startGatewaySubscriptionForRamp((int) $subscription->id, $billingType);

            audit_log('ramp.cobranca_iniciada_manualmente', [
                'account_id'  => $accountId,
                'entity_type' => 'subscription',
                'entity_id'   => $subscription->id,
                'metadata'    => ['billing_type' => $billingType, 'valor' => $result['amount']],
            ]);

            return $this->response->setJSON(['success' => 'Cobrança iniciada no gateway.']);
        } catch (\Exception $e) {
            return $this->response->setJSON(['error' => $e->getMessage()])->setStatusCode(400);
        }
    }

    /**
     * Cancelar assinatura via Admin
     */
    public function cancel($accountId)
    {
        if (!auth()->user()->inGroup('superadmin')) {
            return $this->response->setJSON(['error' => 'Acesso negado.'])->setStatusCode(403);
        }

        $subscription = $this->subscriptionModel->where('account_id', $accountId)->orderBy('id', 'DESC')->first();
        if (!$subscription) {
            return $this->response->setJSON(['error' => 'Assinatura não encontrada.'])->setStatusCode(404);
        }

        try {
            $this->paymentService->cancelSubscription($subscription->id);
            return $this->response->setJSON(['success' => 'Assinatura cancelada com sucesso.']);
        } catch (\Exception $e) {
            return $this->response->setJSON(['error' => $e->getMessage()])->setStatusCode(400);
        }
    }

    /**
     * Suspender assinatura via Admin
     */
    public function suspend($accountId)
    {
        if (!auth()->user()->inGroup('superadmin')) {
            return $this->response->setJSON(['error' => 'Acesso negado.'])->setStatusCode(403);
        }

        $subscription = $this->subscriptionModel->where('account_id', $accountId)->orderBy('id', 'DESC')->first();
        if (!$subscription) {
            return $this->response->setJSON(['error' => 'Assinatura não encontrada.'])->setStatusCode(404);
        }

        try {
            $this->paymentService->suspendSubscription($subscription->id);
            return $this->response->setJSON(['success' => 'Assinatura suspensa com sucesso.']);
        } catch (\Exception $e) {
            return $this->response->setJSON(['error' => $e->getMessage()])->setStatusCode(400);
        }
    }

    /**
     * Trocar plano da conta
     */
    public function upgrade($accountId)
    {
        if (!auth()->user()->inGroup('superadmin')) {
            return $this->response->setJSON(['error' => 'Acesso negado.'])->setStatusCode(403);
        }

        $planId = $this->request->getPost('plan_id');
        if (!$planId) {
            return $this->response->setJSON(['error' => 'Plano não selecionado.'])->setStatusCode(400);
        }
        $billingType = strtoupper((string) ($this->request->getPost('billing_type') ?: 'PIX'));
        if (!in_array($billingType, ['PIX', 'BOLETO', 'CREDIT_CARD'], true)) {
            return $this->response->setJSON(['error' => 'Forma de pagamento inválida.'])->setStatusCode(400);
        }

        $subscription = $this->subscriptionModel->where('account_id', $accountId)->orderBy('id', 'DESC')->first();
        if (!$subscription) {
            return $this->response->setJSON(['error' => 'Assinatura não encontrada.'])->setStatusCode(404);
        }

        try {
            $this->paymentService->changeSubscriptionPlan($accountId, (int)$planId, $billingType);
            return $this->response->setJSON(['success' => 'Plano atualizado com sucesso no gateway.']);
        } catch (\Exception $e) {
            return $this->response->setJSON(['error' => $e->getMessage()])->setStatusCode(400);
        }
    }
}
