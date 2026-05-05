<?php

namespace App\Models;

use CodeIgniter\Model;

class SubscriptionModel extends Model
{
    protected $table            = 'subscriptions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\Subscription::class;
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'account_id', 'plan_id', 'status', 'billing_cycle', 'data_inicio', 'data_fim',
        'proximo_pagamento', 'asaas_subscription_id', 'asaas_customer_id',
        'payment_method', 'next_billing_date'
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [];
    protected array $castHandlers = [];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    // Validation
    protected $validationRules      = [];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    // Callbacks
    protected $allowCallbacks = true;
    protected $beforeInsert   = [];
    protected $afterInsert    = [];
    protected $beforeUpdate   = [];
    protected $afterUpdate    = [];
    protected $beforeFind     = [];
    protected $afterFind      = [];
    protected $beforeDelete   = [];
    protected $afterDelete    = [];

    /**
     * Buscar subscription ativa de uma conta
     */
    public function getActiveSubscriptionByAccount($accountId)
    {
        return $this->where('account_id', $accountId)
                    ->where('status', 'ACTIVE')
                    ->orderBy('created_at', 'DESC')
                    ->first();
    }

    /**
     * Estatísticas de subscriptions
     * @param int|null $accountId Se null, retorna stats globais
     */
    public function getSubscriptionStats($accountId = null)
    {
        $builder = $this->builder();
        
        if ($accountId) {
            $builder->where('account_id', $accountId);
        }
        
        $total = $builder->countAllResults(false);
        
        $active = $builder->where('status', 'ACTIVE')->countAllResults(false);
        $pending = $builder->whereIn('status', ['PENDING', 'AWAITING_PAYMENT'])->countAllResults(false);
        $inactive = $builder->where('status', 'INACTIVE')->countAllResults();
        
        return [
            'total' => $total,
            'active' => $active,
            'pending' => $pending,
            'inactive' => $inactive
        ];
    }

    /**
     * Receita por período
     */
    public function getRevenueByPeriod($start, $end, $accountId = null)
    {
        $builder = $this->db->table('subscriptions s');
        $builder->select('SUM(p.preco_mensal) as revenue, COUNT(s.id) as subscription_count')
                ->join('plans p', 's.plan_id = p.id')
                ->where('s.status', 'ACTIVE')
                ->where('s.created_at >=', $start)
                ->where('s.created_at <=', $end);
        
        if ($accountId) {
            $builder->where('s.account_id', $accountId);
        }
        
        return $builder->get()->getRow();
    }

    public function calculateMRR(): float
    {
        $result = $this->selectSum('plans.preco_mensal', 'total_mrr')
            ->join('plans', 'plans.id = subscriptions.plan_id')
            ->where('subscriptions.status', 'ACTIVE')
            ->first();
            
        return (float) ($result->total_mrr ?? 0.00);
    }

    /**
     * Busca a assinatura mais recente de uma conta (para reutilizar customer_id)
     */
    public function findMostRecentByAccount(int $accountId)
    {
        return $this->where('account_id', $accountId)
                    ->where('asaas_customer_id IS NOT NULL')
                    ->orderBy('id', 'DESC')
                    ->first();
    }

    /**
     * Verifica se a subscription está vencida além do período de carência (3 dias por padrão)
     * @param object|array $subscription
     * @param int $graceDays Dias de carência (padrão 3). Se negativo, sempre retorna false.
     * @return bool
     */
    public function isOverdue($subscription, int $graceDays = 3): bool
    {
        $proximoPagamento = is_array($subscription) ? ($subscription['proximo_pagamento'] ?? null) : ($subscription->proximo_pagamento ?? null);
        $status = is_array($subscription) ? ($subscription['status'] ?? null) : ($subscription->status ?? null);

        if (!$proximoPagamento || $status === 'ACTIVE') {
            return false;
        }

        $nextPaymentDate = new \DateTime($proximoPagamento);
        $graceDateLimit = (new \DateTime())->modify("+{$graceDays} days");

        return $nextPaymentDate < $graceDateLimit;
    }

    /**
     * Verifica se a subscription está dentro do período de carência (graça definido no plano)
     * Usa: plano.carencia_dias
     * @param object|array $subscription
     * @return bool
     */
    public function isInGracePeriod($subscription): bool
    {
        $status = is_array($subscription) ? ($subscription['status'] ?? null) : ($subscription->status ?? null);
        $dataInicio = is_array($subscription) ? ($subscription['data_inicio'] ?? null) : ($subscription->data_inicio ?? null);
        $planId = is_array($subscription) ? ($subscription['plan_id'] ?? null) : ($subscription->plan_id ?? null);

        if ($status !== 'ACTIVE' || !$dataInicio || !$planId) {
            return false;
        }

        // Carrega plan para pegar carencia_dias
        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->find($planId);
        
        if (!$plan || !isset($plan->carencia_dias) || $plan->carencia_dias <= 0) {
            return false;
        }

        $graceEndDate = (new \DateTime($dataInicio))->modify("+{$plan->carencia_dias} days");
        $today = new \DateTime();

        return $today < $graceEndDate;
    }

    /**
     * Verifica se o account associado tem KYC verificado
     * @param int $accountId
     * @return bool
     */
    public function isVerified(int $accountId): bool
    {
        if ($accountId <= 0) {
            return false;
        }

        $accountModel = model('App\Models\AccountModel');
        $account = $accountModel->find($accountId);

        return $account
            && $account->is_verified
            && in_array($account->verification_status, ['APPROVED', 'VERIFIED'], true);
    }
}
