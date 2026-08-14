<?php

namespace App\Services;

use App\Entities\Account;
use App\Models\AccountModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

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
     * Busca uma conta pelo slug de URL (página pública da imobiliária).
     */
    public function getAccountBySlug(string $slug): ?Account
    {
        return $this->accountModel->where('slug', $slug)->first();
    }

    /**
     * Exclui (soft delete) uma conta.
     *
     * Api\V1\AccountController::delete() já chamava este método, mas ele nunca
     * existiu — a chamada levantava um Error ("Call to undefined method"), então
     * DELETE /api/v1/accounts/{id} respondia 500 em 100% dos casos.
     *
     * As subcontas não são apagadas em cascata: a FK de parent_account_id é
     * ON DELETE SET NULL, então os corretores viram contas independentes em vez
     * de sumirem junto com a imobiliária.
     */
    public function deleteAccount(int $id): bool
    {
        if (! $this->accountModel->find($id)) {
            return false;
        }

        return (bool) $this->accountModel->delete($id);
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

        // ESCOPO DE TENANT.
        // Api\V1\AccountController::index() já montava 'id' e 'parent_id' para
        // limitar o que cada conta enxerga, mas este método não conhecia
        // nenhuma das duas chaves e as ignorava em silêncio — o resultado era
        // um dump de TODAS as contas da plataforma (nome, e-mail, telefone e
        // CPF/CNPJ) para qualquer credencial de API válida.
        if (!empty($filters['id'])) {
            $builder->where('id', (int) $filters['id']);
        }

        if (!empty($filters['parent_id'])) {
            // A conta-mãe se vê e vê as subcontas dela.
            $parentId = (int) $filters['parent_id'];
            $builder->groupStart()
                    ->where('id', $parentId)
                    ->orWhere('parent_account_id', $parentId)
                    ->groupEnd();
        }

        return [
            'accounts' => $builder->paginate($perPage),
            'pager'    => $this->accountModel->pager,
        ];
    }

    /**
     * Lista parceiros (contas ativas) para exibição pública.
     *
     * Passa a exigir assinatura vigente (ACTIVE/TRIAL) — antes só olhava
     * `accounts.status`, então uma conta com assinatura cancelada (mas conta
     * não desativada manualmente) seguia na vitrine pública de parceiros.
     */
    public function listPublicPartners(int $perPage = 12): array
    {
        return [
            'partners' => $this->accountModel
                ->select('accounts.*')
                ->join('subscriptions', "subscriptions.account_id = accounts.id AND subscriptions.status IN ('ACTIVE', 'TRIAL')", 'inner')
                ->where('accounts.status', 'ACTIVE')
                ->where('accounts.nome !=', 'Administrador')
                ->groupBy('accounts.id')
                ->orderBy('accounts.nome', 'ASC')
                ->paginate($perPage),
            'pager' => $this->accountModel->pager
        ];
    }

    /**
     * Retorna parceiros em destaque ("Imobiliárias em destaque") para a home
     * — a vitrine EXPOSICAO_VITRINE da proposta comercial (Ouro/Diamante).
     *
     * Antes, "destaque" era só ter logo cadastrado: sem relação com plano,
     * sem checagem de assinatura, sem custo — a antítese do que a proposta
     * pede ("imobiliárias em destaque" como benefício vendido). Passa a
     * exigir a feature `exposicao.vitrine` do plano vigente e reusa o mesmo
     * bloqueio por atraso que já protege a busca pública de imóvel
     * (`getOverdueAccountIdsCached`, 3 dias) — não faz sentido vitrinar quem
     * está impedido de aparecer na busca.
     */
    public function getFeaturedPartners(int $limit = 12): array
    {
        $blockedAccountIds = Factories::models(\App\Models\PaymentTransactionModel::class)
            ->getOverdueAccountIdsCached(3);

        $builder = $this->accountModel
            ->select('accounts.*')
            ->join('subscriptions', "subscriptions.account_id = accounts.id AND subscriptions.status IN ('ACTIVE', 'TRIAL')", 'inner')
            ->join('plans', 'plans.id = subscriptions.plan_id', 'inner')
            ->where('accounts.status', 'ACTIVE')
            ->where('accounts.nome !=', 'Administrador')
            ->where("(plans.features->>'exposicao.vitrine')::boolean IS TRUE", null, false)
            ->groupBy('accounts.id')
            ->groupBy('plans.exposure_weight')
            ->orderBy('plans.exposure_weight', 'DESC')
            ->orderBy('accounts.nome', 'ASC');

        if ($blockedAccountIds !== []) {
            $builder->whereNotIn('accounts.id', $blockedAccountIds);
        }

        return $builder->findAll($limit);
    }

    /**
     * Retorna todas as contas (exceto administrador) ordenadas por nome.
     * Usada para vincular anúncios/propriedades às contas disponíveis.
     */
    public function getAllAccountsSortedByName(): array
    {
        return $this->accountModel
            ->where('tipo_conta !=', 'ADMIN')
            ->orderBy('nome', 'ASC')
            ->findAll();
    }

    /**
     * Verifica se um email já está registrado (Shield identities).
     */
    public function emailExists(string $email): bool
    {
        $db = \Config\Database::connect();
        return $db->table('auth_identities')
                  ->where('type', 'email_password')
                  ->where('secret', $email)
                  ->countAllResults() > 0;
    }

    /**
     * Verifica se um documento (CPF/CNPJ) já está registrado.
     */
    public function documentExists(string $documento, ?int $excludeAccountId = null): bool
    {
        $documento = preg_replace('/[^0-9]/', '', $documento);
        
        $query = $this->accountModel->where('documento', $documento);
        if ($excludeAccountId) {
            $query->where('id !=', $excludeAccountId);
        }
        
        return $query->countAllResults() > 0;
    }

    /**
     * Registra um novo usuário e conta vinculada.
     */
    public function registerUser(array $data)
    {
        $db = \Config\Database::connect();
        $db->transStart();

        try {
            // 1. Create Account
            $accountData = [
                'nome' => $data['nome'],
                'tipo_conta' => $data['tipo_conta'],
                'documento' => preg_replace('/[^0-9]/', '', $data['documento']),
                'status' => 'PENDING',
                'email' => $data['email']
            ];
            
            $this->accountModel->insert($accountData);
            $accountId = $this->accountModel->getInsertID();

            // 2. Create User (Shield)
            // Note: We use the global 'model' helper to get the specific UserModel extended in App if exists, or Shield's
            $users = model('App\Models\UserModel'); 
            
            // users.username é varchar(30). Sem truncar, um e-mail com parte local
            // longa (comum em endereços corporativos, ex.: nome.sobrenome.cargo@empresa.com.br)
            // estoura o limite da coluna. O INSERT falha silenciosamente (DBDebug=false),
            // a transação fica "abortada" no Postgres, e a query seguinte de
            // Shield\UserModel::saveEmailIdentity() explode com um Error (não Exception)
            // não capturado pelo catch abaixo — ver o ajuste de \Throwable também.
            $localPart = mb_substr(explode('@', $data['email'])[0], 0, 27);

            $user = new User([
                'username' => $localPart . rand(100,999),
                'email'    => $data['email'],
                'password' => $data['password'],
                'active'   => 0,
                'account_id' => $accountId
            ]);
            
            $users->save($user);
            $userId = $users->getInsertID();
            
            if (!$userId) {
                throw new \Exception("Erro ao gerar ID do usuário.");
            }
            $user->id = $userId;

            // 3. Assign Group
            $group = 'user';
            if ($data['tipo_conta'] === 'IMOBILIARIA') {
                $group = 'imobiliaria_admin';
            } elseif ($data['tipo_conta'] === 'CORRETOR') {
                $group = 'imobiliaria_corretor';
            }
            
            $user->addGroup($group);

            $db->transComplete();

            if ($db->transStatus() === false) {
                throw new \Exception("Erro na transação de cadastro.");
            }

            return $user;

        } catch (\Throwable $e) {
            // \Throwable, não só \Exception: a falha real que expôs esse gap
            // ("Call to a member function getRow() on false", dentro de
            // insertID() dispachado por Shield\UserModel::saveEmailIdentity()
            // após um INSERT silenciosamente rejeitado) é um \Error em PHP, que
            // um catch(\Exception) não intercepta — a transação ficava aberta e
            // o registro em `accounts` do passo 1 sobrevivia à falha do passo 2,
            // bloqueando permanentemente o mesmo e-mail/documento em tentativas
            // futuras (accountModel->documentExists()/is_unique[auth_identities.secret]
            // encontram a conta órfã).
            $db->transRollback();
            throw $e;
        }
    }
}
