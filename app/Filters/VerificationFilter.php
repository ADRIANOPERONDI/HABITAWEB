<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class VerificationFilter implements FilterInterface
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
            return; // Outro filtro cuida do login
        }

        // Se for SuperAdmin, ignorar trava de verificação
        if (auth()->user()->inGroup('superadmin')) {
            return;
        }

        $userId = auth()->id();
        $db = \Config\Database::connect();
        
        // Buscar Account ID
        $userRow = $db->table('users')->select('account_id')->where('id', $userId)->get()->getRow();
        
        if ($userRow && !empty($userRow->account_id)) {
            // Buscar Status de Verificação
            $account = $db->table('accounts')->select('verification_status')->where('id', $userRow->account_id)->get()->getRow();
            
            // Bloqueio inteligente: Só permite 'APPROVED' em rotas de criação/edição
            if (!$account || $account->verification_status !== 'APPROVED') {
                
                $currentPath = uri_string();
                
                // Rotas que DEVEM ser bloqueadas se não estiver verificado
                // imoveis (create, edit, store, update, delete)
                $restrictedKeywords = [
                    'properties/new', 'properties/create', 'properties/edit', 'properties/update', 'properties/delete',
                    'clients/new', 'clients/create', 'clients/edit', 'clients/update', 'clients/delete',
                    'media/upload', 'media/delete'
                ];
                
                foreach ($restrictedKeywords as $keyword) {
                    if (str_contains($currentPath, $keyword)) {
                        log_message('notice', "[VerificationFilter] Bloqueando acesso à rota {$currentPath} para conta #{$userRow->account_id} (Status: " . ($account->verification_status ?? 'NONE') . ")");
                        
                        return redirect()->to(site_url('admin/profile'))->with('error', 'Sua conta ainda não foi verificada. Para postar ou editar imóveis, você deve enviar seus documentos de identidade no seu perfil e aguardar a aprovação.');
                    }
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
