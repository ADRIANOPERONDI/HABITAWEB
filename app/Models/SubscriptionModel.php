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
        'account_id', 'plan_id', 'status', 'data_inicio', 'data_fim', 'proximo_pagamento',
        'asaas_subscription_id', 'asaas_customer_id', 'payment_method'
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

    /**
     * Calcula o Monthly Recurring Revenue (MRR) total.
     */
    public function calculateMRR(): float
    {
        $result = $this->selectSum('plans.preco_mensal', 'total_mrr')
            ->join('plans', 'plans.id = subscriptions.plan_id')
            ->where('subscriptions.status', 'ACTIVE')
            ->first();
            
        return (float) ($result->total_mrr ?? 0.00);
    }
}

