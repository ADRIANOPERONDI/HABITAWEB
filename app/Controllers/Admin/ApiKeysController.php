<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ApiKeyModel;
use App\Models\AccountModel;

/**
 * Controlador de Gestão de API Keys
 * Super Admin: gerencia chaves de todas as contas
 * Clientes (Imobiliárias): veem e criam apenas suas próprias chaves
 */
class ApiKeysController extends BaseController
{
    protected ApiKeyModel $apiKeyModel;
    protected AccountModel $accountModel;

    public function __construct()
    {
        $this->apiKeyModel = model(ApiKeyModel::class);
        $this->accountModel = model(AccountModel::class);
    }

    /**
     * GET /admin/api-keys
     * Lista chaves:
     * - Super Admin: todas as chaves de todas as contas
     * - Cliente: apenas chaves da própria conta
     */
    public function index()
    {
        $user = auth()->user();
        $isSuperAdmin = $user->inGroup('superadmin');

        $builder = $this->apiKeyModel->select('api_keys.*, accounts.nome as account_name')
                                     ->join('accounts', 'accounts.id = api_keys.account_id', 'left')
                                     ->orderBy('api_keys.created_at', 'DESC');

        // Restrição por conta se não for Super Admin
        if (!$isSuperAdmin && $user->account_id) {
            $builder->where('api_keys.account_id', $user->account_id);
        }

        $keys = $builder->paginate(20);
        $pager = $this->apiKeyModel->pager;

        // Lista de contas (apenas para Super Admin escolher)
        $accounts = [];
        if ($isSuperAdmin) {
            // Busca todas as contas ativas (sem soft delete)
            $accounts = $this->accountModel->where('deleted_at IS NULL')->findAll();
            
            // DEBUG: Se ainda vazio, busca incluindo deletadas para diagnosticar
            if (empty($accounts)) {
                log_message('warning', '[ApiKeysController] Nenhuma conta encontrada. Verificando com withDeleted...');
                $allAccounts = $this->accountModel->withDeleted()->findAll();
                log_message('warning', '[ApiKeysController] Total com withDeleted: ' . count($allAccounts));
            }
        }

        // Fallback para superadmin sem account_id vinculado
        $currentAccountId = $user->account_id ?? ($isSuperAdmin ? 1 : null);

        return view('admin/api-keys/index', [
            'keys' => $keys,
            'pager' => $pager,
            'accounts' => $accounts,
            'isSuperAdmin' => $isSuperAdmin,
            'currentAccountId' => $currentAccountId,
        ]);
    }

    /**
     * POST /admin/api-keys
     * Cria uma nova chave de API
     */
    public function create()
    {
        $user = auth()->user();
        $isSuperAdmin = $user->inGroup('superadmin');

        $name = $this->request->getPost('name');
        $accountId = (int)$this->request->getPost('account_id');
        $rateLimitPerHour = (int)($this->request->getPost('rate_limit_per_hour') ?? 1000);

        // Validação: Super Admin pode escolher conta, Cliente usa a própria
        if (!$isSuperAdmin) {
            if (!$user->account_id) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => 'Usuário não vinculado a uma conta.'
                ]);
            }
            $accountId = $user->account_id;
        }

        // Validação básica
        if (empty($name) || empty($accountId)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Nome e conta são obrigatórios.'
            ]);
        }

        // Gera a chave
        $result = $this->apiKeyModel->generateKey(
            $accountId,
            $name,
            $user->id,
            $rateLimitPerHour
        );

        return $this->response->setJSON($result);
    }

    /**
     * POST /admin/api-keys/{id}/revoke
     * Revoga uma chave de API
     */
    public function revoke($id)
    {
        $user = auth()->user();
        $key = $this->apiKeyModel->find($id);

        if (!$key) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Chave não encontrada.'
            ]);
        }

        // Permissão: Super Admin ou dono da conta
        if (!$user->inGroup('superadmin') && $key->account_id != $user->account_id) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Acesso negado.'
            ]);
        }

        if ($this->apiKeyModel->revokeKey($id)) {
            return $this->response->setJSON([
                'success' => true,
                'message' => 'Chave revogada com sucesso.'
            ]);
        }

        return $this->response->setJSON([
            'success' => false,
            'message' => 'Erro ao revogar chave.'
        ]);
    }

    /**
     * POST /admin/api-keys/{id}/toggle
     * Ativa/Desativa uma chave
     */
    public function toggle($id)
    {
        $user = auth()->user();
        $key = $this->apiKeyModel->find($id);

        if (!$key) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Chave não encontrada.'
            ]);
        }

        // Permissão
        if (!$user->inGroup('superadmin') && $key->account_id != $user->account_id) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Acesso negado.'
            ]);
        }

        // Não pode reativar chave revogada
        if ($key->status === 'revoked') {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Chaves revogadas não podem ser reativadas.'
            ]);
        }

        $newStatus = $key->status === 'active' ? 'inactive' : 'active';
        
        if ($this->apiKeyModel->update($id, ['status' => $newStatus])) {
            return $this->response->setJSON([
                'success' => true,
                'message' => 'Status atualizado com sucesso.',
'newStatus' => $newStatus
            ]);
        }

        return $this->response->setJSON([
            'success' => false,
            'message' => 'Erro ao atualizar status.'
        ]);
    }

    /**
     * DELETE /admin/api-keys/{id}
     * Deleta permanentemente uma chave (soft delete)
     */
    public function delete($id)
    {
        $user = auth()->user();
        $key = $this->apiKeyModel->find($id);

        if (!$key) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Chave não encontrada.'
            ]);
        }

        // Permissão
        if (!$user->inGroup('superadmin') && $key->account_id != $user->account_id) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Acesso negado.'
            ]);
        }

        if ($this->apiKeyModel->delete($id)) {
            return $this->response->setJSON([
                'success' => true,
                'message' => 'Chave deletada com sucesso.'
            ]);
        }

        return $this->response->setJSON([
            'success' => false,
            'message' => 'Erro ao deletar chave.'
        ]);
    }
}
