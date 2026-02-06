<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;

class PaymentHistoryController extends BaseController
{
    protected $transactionModel;
    protected $subscriptionModel;

    public function __construct()
    {
        $this->transactionModel = model('App\Models\PaymentTransactionModel'); // Check if exists or use Query Builder
        $this->subscriptionModel = model('App\Models\SubscriptionModel');
    }

    public function index()
    {
        $userId = service('auth')->id();
        $user = service('auth')->user();
        
        if (!$userId || !$user->account_id) {
            return redirect()->to('login');
        }

        $accountId = $user->account_id;

        // Buscar transações
        // Se model não existir, usar db builder
        $db = \Config\Database::connect();
        $transactions = $db->table('payment_transactions')
                           ->where('account_id', $accountId)
                           ->orderBy('created_at', 'DESC')
                           ->get()
                           ->getResult();

        // Buscar assinatura atual
        $currentSubscription = $this->subscriptionModel->where('account_id', $accountId)
                                                     ->orderBy('id', 'DESC')
                                                     ->first();

        return view('web/financial/history', [
            'transactions' => $transactions,
            'currentSubscription' => $currentSubscription
        ]);
    }

    public function invoice($id)
    {
        // Ver detalhe ou redirecionar para URL do boleto/fatura externa
        $userId = service('auth')->id();
        $user = service('auth')->user();
        $accountId = $user->account_id;

        $db = \Config\Database::connect();
        $transaction = $db->table('payment_transactions')
                          ->where('id', $id)
                          ->where('account_id', $accountId)
                          ->get()
                          ->getRow();

        if (!$transaction) {
            return redirect()->back()->with('error', 'Transação não encontrada.');
        }

        // Se tiver metadata com URL, redirecionar
        // Ou se tiver lógica específica de gateway
        
        // Por enquanto, apenas exibe detalhe simples se não tiver URL externa fácil
        return view('web/financial/invoice_detail', ['transaction' => $transaction]);
    }
}
