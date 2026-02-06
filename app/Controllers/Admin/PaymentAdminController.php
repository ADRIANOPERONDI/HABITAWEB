<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class PaymentAdminController extends BaseController
{
    protected $subscriptionModel;
    protected $transactionModel;
    protected $accountModel;
    protected $planModel;

    public function __construct()
    {
        $this->subscriptionModel = model('App\Models\SubscriptionModel');
        $this->transactionModel = model('App\Models\PaymentTransactionModel');
        $this->accountModel = model('App\Models\AccountModel');
        $this->planModel = model('App\Models\PlanModel');
    }

    /**
     * Dashboard de Pagamentos
     */
    public function index()
    {
        $user = auth()->user();
        $isAdmin = $user->inGroup('superadmin', 'admin');
        
        if (!$isAdmin && !$user->account_id) {
            return redirect()->to('admin')->with('error', 'Acesso negado.');
        }
        
        $accountId = $isAdmin ? null : $user->account_id;
        
        // Estatísticas
        $subscriptionStats = $this->subscriptionModel->getSubscriptionStats($accountId);
        $transactionStats = $this->transactionModel->getTransactionStats($accountId);
        
        // Receita do mês atual
        $startOfMonth = date('Y-m-01 00:00:00');
        $endOfMonth = date('Y-m-t 23:59:59');
        $monthRevenue = $this->subscriptionModel->getRevenueByPeriod($startOfMonth, $endOfMonth, $accountId);
        
        // Receita dos últimos 6 meses (para gráfico)
        $monthlyRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-$i months"));
            $start = $month . '-01 00:00:00';
            $end = date('Y-m-t 23:59:59', strtotime($start));
            
            $revenue = $this->subscriptionModel->getRevenueByPeriod($start, $end, $accountId);
            $monthlyRevenue[] = [
                'month' => date('M/y', strtotime($start)),
                'revenue' => $revenue->revenue ?? 0
            ];
        }
        
        return view('admin/payments/dashboard', [
            'subscriptionStats' => $subscriptionStats,
            'transactionStats' => $transactionStats,
            'monthRevenue' => $monthRevenue->revenue ?? 0,
            'monthlyRevenue' => $monthlyRevenue,
            'isAdmin' => $isAdmin
        ]);
    }

    /**
     * Lista de Transações
     */
    public function transactions()
    {
        $user = auth()->user();
        $isAdmin = $user->inGroup('superadmin', 'admin');
        
        if (!$isAdmin && !$user->account_id) {
            return redirect()->to('admin')->with('error', 'Acesso negado.');
        }
        
        // Buscar contas (para filtro do admin)
        $accounts = [];
        if ($isAdmin) {
            $accounts = $this->accountModel->select('id, name')->findAll();
        }
        
        return view('admin/payments/transactions', [
            'accounts' => $accounts,
            'isAdmin' => $isAdmin
        ]);
    }

    /**
     * API: Buscar transações (para DataTables)
     */
    public function getTransactions()
    {
        $user = auth()->user();
        $isAdmin = $user->inGroup('superadmin', 'admin');
        
        if (!$isAdmin && !$user->account_id) {
            return $this->response->setJSON(['error' => 'Acesso negado'])->setStatusCode(403);
        }
        
        $request = $this->request;
        
        // Filtros
        $filters = [
            'status' => $request->getGet('status'),
            'payment_method' => $request->getGet('payment_method'),
            'start_date' => $request->getGet('start_date'),
            'end_date' => $request->getGet('end_date'),
        ];
        
        // Admin pode filtrar por conta
        if ($isAdmin && $request->getGet('account_id')) {
            $filters['account_id'] = $request->getGet('account_id');
        } elseif (!$isAdmin) {
            // Não-admin vê apenas suas transações
            $filters['account_id'] = $user->account_id;
        }
        
        $builder = $this->transactionModel->searchTransactions($filters);
        
        // Paginação para DataTables
        $start = $request->getGet('start') ?? 0;
        $length = $request->getGet('length') ?? 10;
        
        $totalRecords = $builder->countAllResults(false);
        $data = $builder->limit($length, $start)->get()->getResultArray();
        
        return $this->response->setJSON([
            'draw' => $request->getGet('draw'),
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalRecords,
            'data' => $data
        ]);
    }

    /**
     * Ver detalhes de uma transação
     */
    public function viewTransaction($id)
    {
        $user = auth()->user();
        $isAdmin = $user->inGroup('superadmin', 'admin');
        
        $transaction = $this->transactionModel->find($id);
        
        if (!$transaction) {
            return redirect()->back()->with('error', 'Transação não encontrada.');
        }
        
        // Verificar permissão
        if (!$isAdmin && $transaction['account_id'] != $user->account_id) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }
        
        // Buscar dados relacionados
        $subscription = $this->subscriptionModel->find($transaction['subscription_id']);
        $account = $this->accountModel->find($transaction['account_id']);
        $plan = null;
        
        if ($subscription) {
            $plan = $this->planModel->find($subscription->plan_id);
        }
        
        return view('admin/payments/view_transaction', [
            'transaction' => $transaction,
            'subscription' => $subscription,
            'account' => $account,
            'plan' => $plan,
            'isAdmin' => $isAdmin
        ]);
    }

    /**
     * Exportar transações para CSV
     */
    public function exportTransactions()
    {
        $user = auth()->user();
        $isAdmin = $user->inGroup('superadmin', 'admin');
        
        if (!$isAdmin && !$user->account_id) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }
        
        $request = $this->request;
        
        // Filtros
        $filters = [
            'status' => $request->getGet('status'),
            'payment_method' => $request->getGet('payment_method'),
            'start_date' => $request->getGet('start_date'),
            'end_date' => $request->getGet('end_date'),
        ];
        
        if ($isAdmin && $request->getGet('account_id')) {
            $filters['account_id'] = $request->getGet('account_id');
        } elseif (!$isAdmin) {
            $filters['account_id'] = $user->account_id;
        }
        
        $transactions = $this->transactionModel->searchTransactions($filters)->findAll();
        
        // Gerar CSV
        $filename = 'transacoes_' . date('Y-m-d_His') . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // Cabeçalho
        fputcsv($output, ['ID', 'Data', 'Conta', 'Método', 'Valor', 'Status']);
        
        // Dados
        foreach ($transactions as $transaction) {
            fputcsv($output, [
                $transaction['id'],
                $transaction['created_at'],
                $transaction['account_name'] ?? 'N/A',
                $transaction['payment_method'],
                'R$ ' . number_format($transaction['amount'], 2, ',', '.'),
                $transaction['status']
            ]);
        }
        
        fclose($output);
        exit;
    }
}
