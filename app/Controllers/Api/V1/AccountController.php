<?php

namespace App\Controllers\Api\V1;

use App\Entities\Account;
use App\Services\AccountService;

class AccountController extends BaseController
{
    /** Campos que um não-superadmin pode alterar. */
    private const UPDATABLE_FIELDS = [
        'nome', 'email', 'telefone', 'whatsapp', 'creci', 'logo',
        'whatsapp_hub_config', 'whatsapp_messages_config',
    ];

    protected AccountService $accountService;

    public function __construct()
    {
        $this->accountService = new AccountService();
    }

    /**
     * GET /api/v1/accounts
     * Regras:
     * - Super Admin: vê tudo.
     * - Imobiliária: vê a si mesma e suas subcontas/corretores.
     * - Outros: vê apenas a si mesmo.
     */
    public function index()
    {
        $currentAccountId = $this->currentAccountId();

        if (! $currentAccountId) {
            return $this->respondForbidden('Credencial não vinculada a uma conta.');
        }

        $isSuperAdmin = $this->isSuperAdmin();

        // Só filtros de busca do cliente; o escopo é decidido pelo servidor logo abaixo.
        $filters = array_intersect_key(
            $this->request->getGet(),
            array_flip(['tipo_conta', 'status', 'term', 'page'])
        );

        if (! $isSuperAdmin) {
            // AccountService::listAccounts() antes ignorava 'id'/'parent_id' —
            // o efeito era listar TODAS as contas da plataforma para qualquer
            // chave válida. Agora o service entende as duas chaves.
            if ($this->request->auth_account_type === 'imobiliaria') {
                $filters['parent_id'] = $currentAccountId;
            } else {
                $filters['id'] = $currentAccountId;
            }
        }

        return $this->respondSuccess($this->accountService->listAccounts($filters));
    }

    /**
     * GET /api/v1/accounts/(:id)
     */
    public function show($id = null)
    {
        $account = $this->findAccessibleAccount($id);

        // instanceof, e não is_object(): findAccessibleAccount() devolve OU a
        // entidade OU um objeto Response de erro — e um Response também é
        // objeto, então is_object() deixava o 403 passar como se fosse a conta.
        if (! $account instanceof Account) {
            return $account;
        }

        return $this->respondSuccess(['account' => $account]);
    }

    /**
     * POST /api/v1/accounts
     * Permite que imobiliárias criem subcontas (corretores).
     */
    public function create()
    {
        $currentAccountId = $this->currentAccountId();

        if (! $currentAccountId) {
            return $this->respondForbidden('Credencial não vinculada a uma conta.');
        }

        $isSuperAdmin = $this->isSuperAdmin();
        $accountType  = $this->request->auth_account_type;

        // auth_account_type era sempre null (ApiAuth lia $account->type, coluna
        // inexistente — o nome real é tipo_conta), então este 403 disparava para
        // TODO mundo e o endpoint era inalcançável. Corrigido no filtro.
        if (! $isSuperAdmin && $accountType !== 'imobiliaria') {
            return $this->respondForbidden('Apenas imobiliárias podem criar subcontas via API.');
        }

        $data = $this->getJsonBody();

        if ($data === null) {
            return $this->respondInvalidJson();
        }

        if (empty($data['nome']) || empty($data['email'])) {
            return $this->respondError('nome e email são obrigatórios.', 422, [], self::ERR_VALIDATION);
        }

        if (! $isSuperAdmin) {
            $data['parent_account_id'] = $currentAccountId;
            $data['tipo_conta']        = 'CORRETOR';
            // Nunca deixar o cliente nascer verificado.
            unset($data['is_verified'], $data['verification_status'], $data['status']);
        }

        $result = $this->accountService->trySaveAccount($data);

        if (! $result['success']) {
            return $this->respondError($result['message'], 422, $result['errors'] ?? [], self::ERR_VALIDATION);
        }

        return $this->respondSuccess($result, 'Conta criada com sucesso.', 201);
    }

    /**
     * PUT|PATCH /api/v1/accounts/(:id)
     */
    public function update($id = null)
    {
        $account = $this->findAccessibleAccount($id);

        // instanceof, e não is_object(): findAccessibleAccount() devolve OU a
        // entidade OU um objeto Response de erro — e um Response também é
        // objeto, então is_object() deixava o 403 passar como se fosse a conta.
        if (! $account instanceof Account) {
            return $account;
        }

        $data = $this->getJsonBody();

        if ($data === null) {
            return $this->respondInvalidJson();
        }

        // Lista branca de campos que um não-superadmin pode alterar. NUNCA
        // incluir campos de confiança/status (is_verified, verification_status,
        // tipo_conta, parent_account_id, status) — esses só mudam por fluxo
        // interno/admin.
        if (! $this->isSuperAdmin()) {
            $data = array_intersect_key($data, array_flip(self::UPDATABLE_FIELDS));
        }

        if ($data === []) {
            return $this->respondError(
                'Nenhum campo atualizável foi enviado. Permitidos: ' . implode(', ', self::UPDATABLE_FIELDS) . '.',
                422,
                [],
                self::ERR_VALIDATION
            );
        }

        $result = $this->accountService->trySaveAccount($data, (int) $id);

        if (! $result['success']) {
            return $this->respondError($result['message'], 422, $result['errors'] ?? [], self::ERR_VALIDATION);
        }

        return $this->respondSuccess($result, 'Conta atualizada com sucesso.');
    }

    /**
     * DELETE /api/v1/accounts/(:id)
     */
    public function delete($id = null)
    {
        $account = $this->findAccessibleAccount($id);

        // instanceof, e não is_object(): findAccessibleAccount() devolve OU a
        // entidade OU um objeto Response de erro — e um Response também é
        // objeto, então is_object() deixava o 403 passar como se fosse a conta.
        if (! $account instanceof Account) {
            return $account;
        }

        // Não permite excluir a própria conta principal via API (exige painel).
        if ((int) $id === $this->currentAccountId() && ! $this->isSuperAdmin()) {
            return $this->respondError(
                'Para excluir sua conta principal, utilize o painel administrativo.',
                403,
                [],
                self::ERR_FORBIDDEN
            );
        }

        if ($this->accountService->deleteAccount((int) $id)) {
            return $this->respondSuccess(['account_id' => (int) $id], 'Conta excluída com sucesso.');
        }

        return $this->respondError('Erro ao excluir conta.', 500, [], self::ERR_INTERNAL);
    }

    /**
     * Carrega a conta garantindo que o chamador pode acessá-la: a própria conta,
     * uma subconta sua, ou qualquer uma se for superadmin.
     *
     * @return object|\CodeIgniter\HTTP\ResponseInterface
     */
    private function findAccessibleAccount($id)
    {
        if (! $id) {
            return $this->respondError('ID da conta é obrigatório.', 400, [], self::ERR_INVALID_PAYLOAD);
        }

        $currentAccountId = $this->currentAccountId();

        if (! $currentAccountId) {
            return $this->respondForbidden('Credencial não vinculada a uma conta.');
        }

        $account = $this->accountService->getAccountById((int) $id);

        if (! $account) {
            return $this->respondNotFound('Conta não encontrada.');
        }

        if ($this->isSuperAdmin() || (int) $id === $currentAccountId) {
            return $account;
        }

        // Subconta da imobiliária autenticada? A coluna parent_account_id não
        // existia no banco até a migração 2026-07-27-100200 — antes disso a
        // comparação era sempre "null != $currentAccountId" e o recurso inteiro
        // de subcontas era código morto.
        if ((int) ($account->parent_account_id ?? 0) === $currentAccountId) {
            return $account;
        }

        return $this->respondForbidden('Você não tem permissão para acessar esta conta.');
    }
}
