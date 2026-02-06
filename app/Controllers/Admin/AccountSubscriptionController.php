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
        $plans = model('App\Models\PlanModel')->where('ativo', true)->findAll();

        return $this->response->setJSON([
            'subscription' => $subscription,
            'plans' => $plans,
            'gateway' => $this->paymentService->getActiveGatewayName()
        ]);
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

        $subscription = $this->subscriptionModel->where('account_id', $accountId)->orderBy('id', 'DESC')->first();
        if (!$subscription) {
            return $this->response->setJSON(['error' => 'Assinatura não encontrada.'])->setStatusCode(404);
        }

        try {
            $this->paymentService->changeSubscriptionPlan($accountId, (int)$planId);
            return $this->response->setJSON(['success' => 'Plano atualizado com sucesso no gateway.']);
        } catch (\Exception $e) {
            return $this->response->setJSON(['error' => $e->getMessage()])->setStatusCode(400);
        }
    }
}
