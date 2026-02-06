<?php

namespace App\Models;

use CodeIgniter\Model;

class PaymentTransactionModel extends Model
{
    protected $table            = 'payment_transactions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'subscription_id', 'account_id', 'gateway', 'gateway_transaction_id',
        'amount', 'currency', 'status', 'payment_method', 'metadata',
        'type', 'reference_id', 'description', 'pdf_url', 'invoice_url', 'paid_at'
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [
        'amount' => 'float',
        'metadata' => 'json'
    ];
    protected array $castHandlers = [];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = null;

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
     * Buscar transações por conta com filtros
     */
    public function getTransactionsByAccount($accountId, $filters = [])
    {
        $builder = $this->builder();
        $builder->where('account_id', $accountId);
        
        if (!empty($filters['status'])) {
            $builder->where('status', $filters['status']);
        }
        
        if (!empty($filters['payment_method'])) {
            $builder->where('payment_method', $filters['payment_method']);
        }
        
        if (!empty($filters['start_date'])) {
            $builder->where('created_at >=', $filters['start_date']);
        }
        
        if (!empty($filters['end_date'])) {
            $builder->where('created_at <=', $filters['end_date']);
        }
        
        return $builder->orderBy('created_at', 'DESC');
    }

    /**
     * Estatísticas de transações
     */
    public function getTransactionStats($accountId = null)
    {
        $builder = $this->builder();
        
        if ($accountId) {
            $builder->where('account_id', $accountId);
        }
        
        $total = $builder->countAllResults(false);
        $success = $builder->where('status', 'CONFIRMED')->countAllResults(false);
        $pending = $builder->where('status', 'PENDING')->countAllResults(false);
        $failed = $builder->whereIn('status', ['FAILED', 'CANCELLED'])->countAllResults();
        
        return [
            'total' => $total,
            'success' => $success,
            'pending' => $pending,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($success / $total) * 100, 2) : 0
        ];
    }

    /**
     * Buscar transações com filtros avançados (para Admin)
     */
    public function searchTransactions($filters)
    {
        $builder = $this->builder();
        $builder->select('payment_transactions.*, accounts.name as account_name, accounts.email as account_email')
                ->join('accounts', 'payment_transactions.account_id = accounts.id', 'left');
        
        if (!empty($filters['account_id'])) {
            $builder->where('payment_transactions.account_id', $filters['account_id']);
        }
        
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $builder->whereIn('payment_transactions.status', $filters['status']);
            } else {
                $builder->where('payment_transactions.status', $filters['status']);
            }
        }
        
        if (!empty($filters['payment_method'])) {
            $builder->where('payment_transactions.payment_method', $filters['payment_method']);
        }
        
        if (!empty($filters['start_date'])) {
            $builder->where('payment_transactions.created_at >=', $filters['start_date']);
        }
        
        if (!empty($filters['end_date'])) {
            $builder->where('payment_transactions.created_at <=', $filters['end_date']);
        }
        
        return $builder->orderBy('payment_transactions.created_at', 'DESC');
    }

    /**
     * Calcula a receita total de transações pagas.
     */
    public function calculateTotalRevenue(): float
    {
        $result = $this->selectSum('amount', 'total_revenue')
            ->whereIn('status', ['PAID', 'RECEIVED', 'CONFIRMED'])
            ->first();
            
        return (float) ($result['total_revenue'] ?? 0.00); // returnType is 'array' here
    }

    /**
     * Busca a última transação pendente de uma assinatura
     */
    public function getLastPendingTransaction($subscriptionId)
    {
        return $this->where('subscription_id', $subscriptionId)
                    ->where('status', 'PENDING')
                    ->orderBy('created_at', 'DESC')
                    ->first();
    }

    /**
     * Busca a última transação pendente de uma conta (independente de assinatura)
     */
    public function getLastPendingTransactionByAccount($accountId)
    {
        return $this->where('account_id', $accountId)
                    ->where('status', 'PENDING')
                    ->orderBy('created_at', 'DESC')
                    ->first();
    }
}
