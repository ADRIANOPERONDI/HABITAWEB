<?php

namespace App\Services;

use App\Entities\Account;
use App\Models\AccountModel;
use CodeIgniter\Config\Factories;

class AccountService
{
    protected AccountModel $accountModel;

    public function __construct()
    {
        $this->accountModel = Factories::models(AccountModel::class);
    }

    /**
     * Tenta salvar (criar ou atualizar) uma conta.
     *
     * @param array $data Dados para preencher a entidade.
     * @param int|null $id ID da conta para atualização (opcional).
     * @return array Retorna ['success' => bool, 'data' => Account|null, 'errors' => array, 'message' => string]
     */
    public function trySaveAccount(array $data, ?int $id = null): array
    {
        $account = $id ? $this->accountModel->find($id) : new Account();

        if ($id && !$account) {
            return [
                'success' => false,
                'data'    => null,
                'errors'  => [],
                'message' => 'Conta não encontrada.',
            ];
        }

        $account->fill($data);

        // Se for novo cadastro, define status padrão se não vier
        if (!$account->id && empty($account->status)) {
            $account->status = 'ACTIVE';
        }

        if ($this->accountModel->save($account)) {
            // Recarrega para ter o ID atualizado se for insert
            $savedAccount = $this->accountModel->find($id ?? $this->accountModel->getInsertID());
            
            return [
                'success' => true,
                'data'    => $savedAccount,
                'errors'  => [],
                'message' => 'Conta salva com sucesso.',
            ];
        }

        return [
            'success' => false,
            'data'    => $account,
            'errors'  => $this->accountModel->errors(),
            'message' => 'Erro ao salvar a conta.',
        ];
    }

    /**
     * Busca uma conta pelo ID.
     *
     * @param int $id
     * @return Account|null
     */
    public function getAccountById(int $id): ?Account
    {
        return $this->accountModel->find($id);
    }

    /**
     * Busca contas com filtros e paginação.
     *
     * @param array $filters
     * @param int $perPage
     * @return array
     */
    public function listAccounts(array $filters = [], int $perPage = 20): array
    {
        $builder = $this->accountModel->orderBy('created_at', 'DESC');

        if (!empty($filters['tipo_conta'])) {
            $builder->where('tipo_conta', $filters['tipo_conta']);
        }

        if (!empty($filters['status'])) {
            $builder->where('status', $filters['status']);
        }
        
        if (!empty($filters['term'])) {
            $builder->groupStart()
                    ->like('nome', $filters['term'])
                    ->orLike('email', $filters['term'])
                    ->orLike('documento', $filters['term'])
                    ->groupEnd();
        }

        return [
            'accounts' => $builder->paginate($perPage),
            'pager'    => $this->accountModel->pager,
        ];
    }

    /**
     * Lista parceiros (contas ativas) para exibição pública.
     */
    public function listPublicPartners(int $perPage = 12): array
    {
        return [
            'partners' => $this->accountModel
                ->where('status', 'ACTIVE')
                ->orderBy('nome', 'ASC')
                ->paginate($perPage),
            'pager' => $this->accountModel->pager
        ];
    }

    /**
     * Retorna parceiros em destaque (com logo) para a home.
     */
    public function getFeaturedPartners(int $limit = 12): array
    {
        return $this->accountModel
            ->where('logo !=', null)
            ->where('status', 'ACTIVE')
            ->orderBy('tipo_conta', 'ASC')
            ->findAll($limit);
    }

    /**
     * Retorna todas as contas ordenadas por nome.
     */
    public function getAllAccountsSortedByName(): array
    {
        return $this->accountModel->orderBy('nome', 'ASC')->findAll();
    }
}
