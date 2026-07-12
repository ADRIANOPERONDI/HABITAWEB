<?php

namespace App\Commands;

use App\Models\AccountModel;
use App\Models\ApiKeyModel;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Entities\User;

/**
 * Prepara dados fixos para o E2E de frontend (Playwright): cria (de forma
 * idempotente) um plano, um superadmin e um tenant "prontos para uso" com
 * credenciais fixas e conhecidas, para as jornadas de navegador poderem logar
 * sem precisar inspecionar o banco.
 *
 * O comando só funciona quando o Playwright injeta o marcador explícito de E2E
 * e a conexão aponta exatamente para `habitaweb_test`. As duas condições são
 * verificadas antes de migrations ou qualquer escrita.
 */
class E2ESetup extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'e2e:setup';
    protected $description = 'Prepara dados fixos (plano/superadmin/tenant) para os testes de frontend (Playwright), sem apagar nada.';

    public const SUPERADMIN_EMAIL    = 'e2e-superadmin@teste.habitaweb.local';
    public const SUPERADMIN_PASSWORD = 'E2ESuperAdmin#Teste123';
    public const TENANT_EMAIL        = 'e2e-tenant@teste.habitaweb.local';
    public const TENANT_PASSWORD     = 'E2ETenant#Teste123';
    public const PLAN_CHAVE          = 'E2E_PLAYWRIGHT';
    public const PENDING_KYC_EMAIL    = 'e2e-pending-kyc@teste.habitaweb.local';
    public const PENDING_KYC_PASSWORD = 'E2EPendingKyc#Teste123';
    public const REVIEW_TARGET_EMAIL  = 'e2e-review-target@teste.habitaweb.local';

    public function run(array $params)
    {
        if (ENVIRONMENT === 'production' || env('HABITAWEB_E2E_TESTING', '0') !== '1') {
            CLI::error('e2e:setup bloqueado: execute somente pelo Playwright com o ambiente E2E isolado.');

            return EXIT_ERROR;
        }

        $db = \Config\Database::connect();
        $databaseName = (string) $db->getDatabase();

        if (! hash_equals('habitaweb_test', $databaseName)) {
            CLI::error('e2e:setup bloqueado: o banco conectado não é habitaweb_test.');

            return EXIT_ERROR;
        }

        CLI::write('Banco de teste validado: habitaweb_test', 'yellow');

        CLI::write('Rodando migrations (idempotente)...', 'yellow');
        \Config\Services::migrations()->setNamespace(null)->latest();

        $this->ensurePlan();
        $this->ensureSuperAdmin();
        $this->ensureReadyTenant();
        $this->ensurePendingVerificationAccount();
        $this->ensureReviewTargetAccount();
        $this->ensureCheckoutCoupon();
        $this->ensurePublicProperty();

        // Busca/mapa usam cache; a limpeza é restrita ao Redis DB 6 do E2E.
        cache()->clean();

        CLI::write('E2E setup concluído.', 'green');
    }

    private function ensurePlan(): void
    {
        $planModel = new PlanModel();
        if ($planModel->where('chave', self::PLAN_CHAVE)->first()) {
            CLI::write('Plano de teste já existe, pulando.', 'cyan');
            return;
        }

        $planModel->insert([
            'chave'                 => self::PLAN_CHAVE,
            'nome'                  => 'Plano E2E Playwright',
            'limite_imoveis_ativos' => 45,
            'preco_mensal'          => 199.90,
            'carencia_dias'         => 3,
            'ativo'                 => true,
        ]);

        CLI::write('Plano de teste criado: ' . self::PLAN_CHAVE, 'green');
    }

    private function ensureSuperAdmin(): void
    {
        $db = \Config\Database::connect();
        $exists = $db->table('auth_identities')->where('secret', self::SUPERADMIN_EMAIL)->countAllResults();

        if ($exists > 0) {
            CLI::write('Superadmin já existe, pulando.', 'cyan');
            return;
        }

        $userModel = new UserModel();
        $userModel->save(new User([
            'username' => 'e2e_superadmin',
            'email'    => self::SUPERADMIN_EMAIL,
            'password' => self::SUPERADMIN_PASSWORD,
            'active'   => 1,
        ]));

        $user = $userModel->find($userModel->getInsertID());
        $user->addGroup('superadmin');

        CLI::write('Superadmin criado: ' . self::SUPERADMIN_EMAIL, 'green');
    }

    /**
     * Tenant com KYC aprovado + assinatura ACTIVE — mesma convenção de
     * tests/_support/Factories/TenantFactory.php (Fase 1), reimplementada aqui em
     * PHP puro porque o Node (Playwright) não pode chamar classes PHP diretamente.
     */
    private function ensureReadyTenant(): void
    {
        $db = \Config\Database::connect();
        $exists = $db->table('auth_identities')->where('secret', self::TENANT_EMAIL)->countAllResults();

        if ($exists > 0) {
            CLI::write('Tenant de teste já existe, pulando.', 'cyan');
            return;
        }

        $accountModel = new AccountModel();
        $accountModel->insert([
            'tipo_conta'          => 'IMOBILIARIA',
            'nome'                => 'E2E Tenant Imobiliária',
            'documento'           => '00000000000100',
            'email'               => self::TENANT_EMAIL,
            'telefone'            => '11999990000',
            'status'              => 'ACTIVE',
            'is_verified'         => true,
            'verification_status' => 'APPROVED',
        ]);
        $accountId = $accountModel->getInsertID();

        $plan = (new PlanModel())->where('chave', self::PLAN_CHAVE)->first();

        (new SubscriptionModel())->insert([
            'account_id'        => $accountId,
            'plan_id'           => $plan->id,
            'status'            => 'ACTIVE',
            'billing_cycle'     => 'mensal',
            'data_inicio'       => date('Y-m-d'),
            'data_fim'          => date('Y-m-d', strtotime('+1 year')),
            'proximo_pagamento' => date('Y-m-d', strtotime('+1 month')),
        ]);

        $userModel = new UserModel();
        $userModel->save(new User([
            'username' => 'e2e_tenant',
            'email'    => self::TENANT_EMAIL,
            'password' => self::TENANT_PASSWORD,
            'active'   => 1,
        ]));
        $userId = $userModel->getInsertID();

        $db->table('users')->where('id', $userId)->update(['account_id' => $accountId]);
        $userModel->find($userId)->addGroup('user');

        (new ApiKeyModel())->generateKey($accountId, 'E2E Frontend Key', $userId);

        CLI::write('Tenant de teste criado: ' . self::TENANT_EMAIL, 'green');
    }

    /**
     * Conta com verification_status=NONE (nunca enviou KYC) e usuário Shield
     * próprio, dedicada à jornada de upload/liveness em admin/profile (login
     * como este tenant — AdminAuth mantém qualquer rota dentro do
     * allowlist/redireciona pra admin/profile enquanto o KYC não é aprovado,
     * então nunca precisa de assinatura).
     *
     * Importante: NÃO pode ser PENDING com os 3 documentos já preenchidos —
     * nesse estado admin/profile.php troca o formulário de upload por uma
     * mensagem "em análise" somente leitura (ver o elseif verification_status
     * === 'PENDING' em app/Views/Admin/profile/index.php), escondendo
     * #idFrontInput/#idBackInput/#selfieInput/#startLivenessBtn — exatamente
     * os elementos que este teste precisa. Só NONE (ou REJECTED) mostra o
     * formulário de envio.
     *
     * Também NÃO é a mesma conta usada pelo teste de revisor
     * (ensureReviewTargetAccount) de propósito: aquele teste aprova a conta de
     * verdade, o que tornaria esta aqui inutilizável nas execuções seguintes.
     */
    private function ensurePendingVerificationAccount(): void
    {
        $db = \Config\Database::connect();
        // Checa pela identidade Shield (auth_identities), não pela account: se um
        // run anterior tivesse falhado entre criar a account e criar o usuário
        // (ex.: Ctrl+C, erro), checar só a account deixaria essa órfã sem login
        // permanentemente "pulada" nos runs seguintes.
        $hasIdentity = $db->table('auth_identities')->where('secret', self::PENDING_KYC_EMAIL)->countAllResults() > 0;
        if ($hasIdentity) {
            CLI::write('Conta de KYC pendente já existe, pulando.', 'cyan');
            return;
        }

        $accountModel = new AccountModel();
        // Reaproveita a account se um run anterior já a criou (mesmo cenário de
        // falha parcial descrito acima), em vez de arriscar duplicar o e-mail.
        $account = $accountModel->where('email', self::PENDING_KYC_EMAIL)->first();
        if (!$account) {
            $accountModel->insert([
                'tipo_conta'          => 'IMOBILIARIA',
                'nome'                => 'E2E Pending KYC Imobiliária',
                'documento'           => '00000000000200',
                'email'               => self::PENDING_KYC_EMAIL,
                'telefone'            => '11999990000',
                'status'              => 'PENDING',
                'is_verified'         => false,
                'verification_status' => 'NONE',
            ]);
            $accountId = $accountModel->getInsertID();
        } else {
            $accountId = $account->id;
        }

        $userModel = new UserModel();
        $userModel->save(new User([
            'username' => 'e2e_pending_kyc',
            'email'    => self::PENDING_KYC_EMAIL,
            'password' => self::PENDING_KYC_PASSWORD,
            'active'   => 1,
        ]));
        $userId = $userModel->getInsertID();

        $db->table('users')->where('id', $userId)->update(['account_id' => $accountId]);
        $userModel->find($userId)->addGroup('user');

        CLI::write('Conta de KYC pendente criada: ' . self::PENDING_KYC_EMAIL, 'green');
    }

    /**
     * Conta-alvo fixa da jornada de REVISOR (admin/verification): o teste
     * aprova/rejeita ela de verdade, então diferente das outras, este passo
     * faz upsert — se já existe, reseta verification_status pra PENDING (só
     * esse campo próprio da fixture, nada de outra tabela) pra suíte continuar
     * repetível em execuções futuras. Sem usuário Shield: quem interage é o
     * superadmin revisando, essa conta nunca loga.
     */
    private function ensureReviewTargetAccount(): void
    {
        $accountModel = new AccountModel();
        $existing = $accountModel->where('email', self::REVIEW_TARGET_EMAIL)->first();

        if ($existing) {
            $accountModel->update($existing->id, [
                'is_verified'         => false,
                'verification_status' => 'PENDING',
                'verification_notes'  => null,
            ]);
            CLI::write('Conta-alvo de revisão resetada para PENDING.', 'cyan');
            return;
        }

        $accountModel->insert([
            'tipo_conta'          => 'IMOBILIARIA',
            'nome'                => 'E2E Review Target Imobiliária',
            'documento'           => '00000000000300',
            'email'               => self::REVIEW_TARGET_EMAIL,
            'telefone'            => '11999990000',
            'status'              => 'PENDING',
            'is_verified'         => false,
            'verification_status' => 'PENDING',
            'id_front'            => 'uploads/kyc/e2e/id_front.jpg',
            'id_back'             => 'uploads/kyc/e2e/id_back.jpg',
            'selfie'              => 'uploads/kyc/e2e/selfie.jpg',
        ]);

        CLI::write('Conta-alvo de revisão criada: ' . self::REVIEW_TARGET_EMAIL, 'green');
    }

    private function ensureCheckoutCoupon(): void
    {
        $db = \Config\Database::connect();
        $coupon = $db->table('coupons')->where('code', 'OFERTA524OFF')->get()->getRow();
        $data = [
            'account_id'     => null,
            'description'    => 'Cupom fixo do Playwright',
            'discount_type'  => 'percent',
            'discount_value' => 10,
            'max_uses'       => null,
            'used_count'     => 0,
            'valid_from'     => null,
            'valid_until'    => null,
            'is_active'      => true,
            'updated_at'     => date('Y-m-d H:i:s'),
        ];

        if ($coupon) {
            $db->table('coupons')->where('id', $coupon->id)->update($data);
            CLI::write('Cupom E2E resetado.', 'cyan');
            return;
        }

        $data['code'] = 'OFERTA524OFF';
        $data['created_at'] = date('Y-m-d H:i:s');
        $db->table('coupons')->insert($data);
        CLI::write('Cupom E2E criado.', 'green');
    }

    private function ensurePublicProperty(): void
    {
        $db = \Config\Database::connect();
        $exists = $db->table('properties')->where('titulo', 'E2E Imóvel Público')->countAllResults() > 0;
        if ($exists) {
            CLI::write('Imóvel público E2E já existe, pulando.', 'cyan');
            return;
        }

        $account = $db->table('accounts')->where('email', self::TENANT_EMAIL)->get()->getRow();
        $identity = $db->table('auth_identities')->where('secret', self::TENANT_EMAIL)->get()->getRow();
        if (! $account || ! $identity) {
            throw new \RuntimeException('Fixture tenant ausente ao criar imóvel E2E.');
        }

        $db->table('properties')->insert([
            'account_id'          => $account->id,
            'user_id_responsavel' => $identity->user_id,
            'tipo_negocio'        => 'VENDA',
            'tipo_imovel'         => 'APARTAMENTO',
            'titulo'              => 'E2E Imóvel Público',
            'descricao'           => 'Fixture isolada para busca, mapa, favoritos e lead.',
            'preco'               => 350000,
            'area_total'          => 75,
            'quartos'             => 2,
            'banheiros'           => 1,
            'vagas'               => 1,
            'estado'              => 'SP',
            'cidade'              => 'São Paulo',
            'bairro'              => 'Centro',
            'latitude'            => -23.5505,
            'longitude'           => -46.6333,
            'status'              => 'ACTIVE',
            'score_qualidade'     => 100,
            'publicado_em'        => date('Y-m-d H:i:s'),
            'created_at'          => date('Y-m-d H:i:s'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ]);

        CLI::write('Imóvel público E2E criado.', 'green');
    }
}
