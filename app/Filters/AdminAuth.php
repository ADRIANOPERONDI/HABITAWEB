<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Authentication\Authenticators\Session;

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

        $userId = (int) auth()->id();
        if ($userId > 0 && $this->isSuperAdmin($userId)) {
            return;
        }

        // Check if user is active (email verified)
        $user = auth()->user();
        if ($user && ! $user->active) {
            /** @var Session $authenticator */
            $authenticator = auth('session')->getAuthenticator();
            if (! $authenticator->hasAction()) {
                $authenticator->startUpAction('register', $user);
            }

            log_message('debug', '[AdminAuth] Redirecionando usuário INATIVO (' . $user->id . ') para ativação.');
            return redirect()->to(site_url('ativacao/codigo'));
        }

        // --- PAYMENT LOCK BLOCK ---
        $currentPath = uri_string();
        log_message('debug', '[AdminAuth] Verificando Rota: ' . $currentPath);

        // Allowed paths for unpaid users or users needing verification.
        // The profile route must stay open so the user can upload KYC documents.
        $allowed = ['checkout', 'admin/logout', 'admin/profile', 'admin/subscription', 'api-keys', 'ativacao/'];
        foreach ($allowed as $path) {
            if (str_starts_with($currentPath, $path)) {
                log_message('debug', '[AdminAuth] Rota permitida: ' . $currentPath);
                return;
            }
        }

        // Force Re-fetch to bypass Session Cache issues
        if ($userId) {
            $db = \Config\Database::connect();
            
            // 1. Get Account ID directly from DB
            $userRow = $db->table('users')->select('account_id')->where('id', $userId)->get()->getRow();
            
            if ($userRow && !empty($userRow->account_id)) {
                $accountId = (int) $userRow->account_id;

                // Non-admin precisa KYC aprovado. O status aprovado e a fonte de verdade;
                // flags/arquivos podem ficar inconsistentes em dados antigos.
                if (!$this->isKycVerified($accountId)) {
                    return redirect()->to('admin/profile')->with('error', 'Complete sua verificação de identidade (KYC) para acessar o painel.');
                }

                // Regra de produção espelhada dos E2E: non-admin precisa assinatura ativa.
                $hasActiveSubscription = $db->table('subscriptions')
                    ->where('account_id', $accountId)
                    ->where('status', 'ACTIVE')
                    ->countAllResults() > 0;

                if (!$hasActiveSubscription) {
                    return redirect()->to('admin/subscription')->with('error', 'Você precisa de uma assinatura ativa para acessar o painel.');
                }

                // 2. Get Account Status
                $account = $db->table('accounts')->select('status')->where('id', $accountId)->get()->getRow();
                
                // 3. Verifica faturas pendentes atrasadas há 3+ dias para bloquear (mesmo se a conta ainda estiver ACTIVE no banco)
                $txModel = model('App\Models\PaymentTransactionModel');
                $isBlockedByOverdue = $txModel->isAccountBlockedByOverdue($accountId, 3);

                // PROACTIVE SYNC: Se parecer bloqueado, tenta sincronizar com o Gateway antes de barrar
                if ($isBlockedByOverdue) {
                    try {
                        $paymentService = new \App\Services\PaymentService();
                        $paymentService->syncPendingPayments($accountId);
                        // Re-check after sync
                        $isBlockedByOverdue = $txModel->isAccountBlockedByOverdue($accountId, 3);
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
                                ->where('account_id', $accountId)
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

    private function isSuperAdmin(int $userId): bool
    {
        $db = \Config\Database::connect();

        return $db->table('auth_groups_users')
            ->where('user_id', $userId)
            ->where('group', 'superadmin')
            ->countAllResults() > 0;
    }

    private function isKycVerified(int $accountId): bool
    {
        $db = \Config\Database::connect();
        $account = $db->table('accounts')
            ->select('is_verified, verification_status')
            ->where('id', $accountId)
            ->get()
            ->getRow();

        if (!$account) {
            return false;
        }

        return in_array($account->verification_status, ['APPROVED', 'VERIFIED'], true);
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
