<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AdminAuth implements FilterInterface
{
    /**
     * Do whatever processing this filter needs to do.
     * By default it should not return anything during normal execution.
     * However, it may return an instance of the ResponseInterface.
     * If it does, then the controller execution will stop and that
     * Response will be sent back to the client, allowing for error pages,
     * redirects, etc.
     *
     * @param RequestInterface $request
     * @param array|null       $arguments
     *
     * @return mixed
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! auth()->loggedIn()) {
            return redirect()->to(site_url('admin/login'));
        }

        // --- PAYMENT LOCK BLOCK ---
        $currentPath = uri_string();
        log_message('debug', '[AdminAuth] Verificando Rota: ' . $currentPath);
        
        // Allowed paths for unpaid users or users needing verification
        $allowed = ['checkout', 'admin/logout', 'admin/subscription', 'api-keys', 'auth/a/'];
        foreach ($allowed as $path) {
            if (str_starts_with($currentPath, $path)) {
                log_message('debug', '[AdminAuth] Rota permitida: ' . $currentPath);
                return;
            }
        }

        // Check if user is active (email verified)
        $user = auth()->user();
        if ($user && ! $user->active) {
            return redirect()->to(site_url('auth/a/show'));
        }

        // Force Re-fetch to bypass Session Cache issues
        $userId = auth()->id();
        if ($userId) {
            $db = \Config\Database::connect();
            
            // 1. Get Account ID directly from DB
            $userRow = $db->table('users')->select('account_id')->where('id', $userId)->get()->getRow();
            
            if ($userRow && !empty($userRow->account_id)) {
                // 2. Get Account Status
                $account = $db->table('accounts')->select('status')->where('id', $userRow->account_id)->get()->getRow();
                
                // 3. Verifica faturas pendentes atrasadas há 3+ dias para bloquear (mesmo se a conta ainda estiver ACTIVE no banco)
                $txModel = model('App\Models\PaymentTransactionModel');
                $isBlockedByOverdue = $txModel->isAccountBlockedByOverdue($userRow->account_id, 3);

                // PROACTIVE SYNC: Se parecer bloqueado, tenta sincronizar com o Gateway antes de barrar
                if ($isBlockedByOverdue) {
                    try {
                        $paymentService = new \App\Services\PaymentService();
                        $paymentService->syncPendingPayments($userRow->account_id);
                        // Re-check after sync
                        $isBlockedByOverdue = $txModel->isAccountBlockedByOverdue($userRow->account_id, 3);
                    } catch (\Exception $e) {
                        log_message('error', '[AdminAuth] Falha na sincronização proativa: ' . $e->getMessage());
                    }
                }

                // Priority 1: Strict block by overdue invoices (> 3 days grace period)
                if ($isBlockedByOverdue) {
                    return redirect()->to('admin/subscription')->with('error', lang('App.filter_overdue_error'));
                }

                // Priority 2: Block by Account Status (PENDING, SUSPENDED, etc)
                // If account is SUSPENDED but NOT strictly overdue (isBlockedByOverdue is false), 
                // we allow access to avoid sync issues/false positives for future invoices.
                if ($account && $account->status !== 'ACTIVE' && $account->status !== 'SUSPENDED') {
                     
                     // Check if there is a pending subscription (New Account flow)
                     $hasPendingSub = $db->table('subscriptions')
                        ->where('account_id', $userRow->account_id)
                        ->where('status', 'PENDING')
                        ->countAllResults() > 0;

                     if ($hasPendingSub) {
                        return redirect()->to('admin/subscription')->with('error', lang('App.filter_pending_activation'));
                     }
                     
                     return redirect()->to('admin/subscription')->with('info', lang('App.filter_inactive_account'));
                }
            }
        }
    }

    /**
     * Allows After filters to inspect and modify the response
     * object as needed. This method does not allow allowing
     * after filters to short-circuit the controller execution.
     *
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param array|null        $arguments
     *
     * @return mixed
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        //
    }
}
