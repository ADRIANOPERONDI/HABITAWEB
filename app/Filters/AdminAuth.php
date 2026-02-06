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
        
        // Allowed paths for unpaid users
        $allowed = ['checkout', 'admin/logout', 'admin/subscription', 'api-keys'];
        foreach ($allowed as $path) {
            if (str_starts_with($currentPath, $path)) {
                log_message('debug', '[AdminAuth] Rota permitida: ' . $currentPath);
                return;
            }
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

                // If not ACTIVE, block access
                if ($account && $account->status !== 'ACTIVE') {
                     // Check if there is a pending subscription
                     $hasPendingSub = $db->table('subscriptions')
                        ->where('account_id', $userRow->account_id)
                        ->whereIn('status', ['PENDING', 'AWAITING_PAYMENT'])
                        ->countAllResults() > 0;

                     if ($hasPendingSub) {
                        return redirect()->to('admin/subscription')->with('error', 'Sua conta está pendente de pagamento. Acesse os detalhes da fatura abaixo.');
                     }
                     
                     return redirect()->to('admin/subscription')->with('info', 'Escolha um plano para ativar sua conta.');
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
