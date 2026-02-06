<?php

namespace App\Services;

use App\Models\PaymentTransactionModel;
use App\Models\SubscriptionModel;
use CodeIgniter\Config\Factories;

class FinancialService
{
    protected PaymentTransactionModel $transactionModel;
    protected SubscriptionModel $subscriptionModel;

    public function __construct()
    {
        $this->transactionModel  = Factories::models(PaymentTransactionModel::class);
        $this->subscriptionModel = Factories::models(SubscriptionModel::class);
    }

    /**
     * Coleta todos os dados para o dashboard financeiro.
     */
    public function getFinancialDashboardData(): array
    {
        return [
            'mrr'                => $this->subscriptionModel->calculateMRR(),
            'activeSubscribers'  => $this->subscriptionModel->where('status', 'ACTIVE')->countAllResults(),
            'overdueSubscribers' => $this->subscriptionModel->where('status', 'OVERDUE')->countAllResults(),
            'canceledSubscribers'=> $this->subscriptionModel->where('status', 'CANCELLED')->countAllResults(),
            'recentTransactions' => $this->transactionModel->searchTransactions(['limit' => 10])->limit(10)->get()->getResult(),
            'totalRevenue'       => $this->transactionModel->calculateTotalRevenue(),
        ];
    }
}
