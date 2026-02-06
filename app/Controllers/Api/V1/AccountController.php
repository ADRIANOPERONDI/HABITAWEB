<?php

namespace App\Controllers\Api\V1;

use App\Services\AccountService;
use CodeIgniter\Config\Factories;

class AccountController extends BaseController
{
    protected AccountService $accountService;

    public function __construct()
    {
        $this->accountService = new AccountService(); // Ou via Factories se preferir injeção
    }

    /**
     * GET /api/v1/accounts
     * Regras: 
     * - Super Admin: Vê tudo.
     * - Imobiliária: Vê apenas suas subcontas/corretores.
     * - Outros: Vê apenas a si mesmo.
     */
    public function index()
    {
        $currentAccountId = $this->request->auth_account_id;
        $isSuperAdmin     = $this->request->auth_user_id == 1; 
        $accountType      = $this->request->auth_account_type;
        
        $filters = $this->request->getGet();

        if (!$isSuperAdmin) {
            // Se for imobiliária, vê subcontas
            if ($accountType === 'imobiliaria') {
                $filters['parent_id'] = $currentAccountId;
            } else {
                // Caso contrário, vê apenas a si mesmo
                $filters['id'] = $currentAccountId;
            }
        }

        $result = $this->accountService->listAccounts($filters);
        return $this->respondSuccess($result);
    }

    /**
     * GET /api/v1/accounts/(:id)
     */
    public function show($id = null)
    {
        $currentAccountId = $this->request->auth_account_id;
        $isSuperAdmin     = $this->request->auth_user_id == 1;

        if (!$isSuperAdmin && $id != $currentAccountId) {
            // Verificar se o ID pertence à imobiliária (se for subconta)
            $account = $this->accountService->getAccountById($id);
            if (!$account || $account->parent_account_id != $currentAccountId) {
                return $this->failForbidden('Você não tem permissão para acessar esta conta');
            }
        } else {
            $account = $this->accountService->getAccountById($id);
        }

        if (!$account) {
            return $this->failNotFound('Conta não encontrada');
        }
        return $this->respondSuccess($account);
    }

    /**
     * POST /api/v1/accounts
     * Permite que imobiliárias criem subcontas (corretores)
     */
    public function create()
    {
        $isSuperAdmin     = $this->request->auth_user_id == 1;
        $accountType      = $this->request->auth_account_type;

        if (!$isSuperAdmin && $accountType !== 'imobiliaria') {
            return $this->failForbidden('Apenas imobiliárias podem criar subcontas via API');
        }

        $data = $this->request->getJSON(true);
        
        // Se for imobiliária, força o parent_id
        if (!$isSuperAdmin) {
            $data['parent_account_id'] = $this->request->auth_account_id;
            $data['type'] = 'corretor'; 
        }

        $result = $this->accountService->trySaveAccount($data);

        if ($result['success']) {
            return $this->respondCreated($result);
        }

        return $this->respondError($result['message'], 400, $result['errors'] ?? []);
    }

    /**
     * PUT /api/v1/accounts/(:id)
     */
    public function update($id = null)
    {
        $currentAccountId = $this->request->auth_account_id;
        $isSuperAdmin     = $this->request->auth_user_id == 1;

        if (!$isSuperAdmin && $id != $currentAccountId) {
            // Verifica se é subconta da imobiliária
            $account = $this->accountService->getAccountById($id);
            if (!$account || $account->parent_account_id != $currentAccountId) {
                return $this->failForbidden('Apenas imobiliárias podem editar suas subcontas');
            }
        }

        $data = $this->request->getJSON(true);
        
        // Impede mudança de tipo por não-admin
        if (!$isSuperAdmin) {
            unset($data['type'], $data['parent_account_id']);
        }

        $result = $this->accountService->trySaveAccount($data, $id);

        if ($result['success']) {
            return $this->respondSuccess($result);
        }

        return $this->respondError($result['message'], 400, $result['errors'] ?? []);
    }

    /**
     * DELETE /api/v1/accounts/(:id)
     */
    public function delete($id = null)
    {
        $currentAccountId = $this->request->auth_account_id;
        $isSuperAdmin     = $this->request->auth_user_id == 1;

        if (!$isSuperAdmin && $id != $currentAccountId) {
            $account = $this->accountService->getAccountById($id);
            if (!$account || $account->parent_account_id != $currentAccountId) {
                return $this->failForbidden('Você não tem permissão para excluir esta conta');
            }
        }

        // Não permite excluir a si mesmo via API por segurança (exige painel)
        if ($id == $currentAccountId && !$isSuperAdmin) {
             return $this->fail('Para excluir sua conta principal, utilize o painel administrativo.');
        }

        $result = $this->accountService->deleteAccount($id);
        if ($result) {
            return $this->respondNoContent();
        }

        return $this->fail('Erro ao excluir conta');
    }
}
