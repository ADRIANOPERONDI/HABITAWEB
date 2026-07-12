<?php

namespace Tests\Support\Factories;

use App\Models\AccountModel;
use App\Models\ApiKeyModel;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Monta um tenant "pronto para uso" em testes: conta com KYC aprovado + assinatura
 * ACTIVE (sem fatura vencida) + usuário Shield vinculado + grupo de auth.
 *
 * Esse é o gap identificado na auditoria: sem isso, App\Filters\AdminAuth bloqueia
 * todo o painel (KYC pendente / sem assinatura ativa) e não há como escrever um
 * feature test do painel admin ou de isolamento multi-tenant sem repetir esse boilerplate
 * em cada teste.
 *
 * Uso típico num teste:
 *   $tenant = (new TenantFactory())->create();
 *   $this->actingAs($tenant['user'])->get('admin/dashboard')->assertOK();
 */
class TenantFactory
{
    private static int $sequence = 0;

    /**
     * Cria um tenant completo: account (verification_status=APPROVED), plano (por
     * padrão PRATA, precisa existir via PlanSeeder), subscription ACTIVE, usuário
     * Shield no grupo 'user' (não superadmin/admin — a ideia é exercer o gate real,
     * não pular ele).
     *
     * @param array $overrides Sobrescreve campos da conta (nome, tipo_conta, email, documento...)
     * @param string $planKey Chave do plano semeado por PlanSeeder (PRATA|OURO|DIAMANTE)
     * @return array{account: \App\Entities\Account, user: \App\Entities\User, subscription: \App\Entities\Subscription, password: string}
     */
    public function create(array $overrides = [], string $planKey = 'PRATA'): array
    {
        $seq   = ++self::$sequence;
        $stamp = $seq . '_' . bin2hex(random_bytes(4));

        $accountModel = new AccountModel();
        $accountData  = array_merge([
            'tipo_conta'          => 'IMOBILIARIA',
            'nome'                => "Tenant Teste {$stamp}",
            'documento'           => '00000000' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
            'email'               => "tenant{$stamp}@teste.habitaweb.local",
            'telefone'            => '11999990000',
            'status'              => 'ACTIVE',
            'is_verified'         => true,
            'verification_status' => 'APPROVED',
        ], $overrides);

        if (! $accountModel->insert($accountData)) {
            throw new \RuntimeException('TenantFactory: falha ao criar account — ' . json_encode($accountModel->errors()));
        }
        $account = $accountModel->find($accountModel->getInsertID());

        $plan = (new PlanModel())->where('chave', $planKey)->first();
        if (! $plan) {
            throw new \RuntimeException("TenantFactory: plano '{$planKey}' não encontrado — rode PlanSeeder antes.");
        }

        $subscriptionModel = new SubscriptionModel();
        $subscriptionModel->insert([
            'account_id'        => $account->id,
            'plan_id'           => $plan->id,
            'status'            => 'ACTIVE',
            'billing_cycle'     => 'mensal',
            'data_inicio'       => date('Y-m-d'),
            'data_fim'          => date('Y-m-d', strtotime('+1 year')),
            'proximo_pagamento' => date('Y-m-d', strtotime('+1 month')),
        ]);
        $subscription = $subscriptionModel->find($subscriptionModel->getInsertID());

        $password  = 'TenantTeste#' . bin2hex(random_bytes(4));
        $userModel = new UserModel();
        $shieldUser = new User([
            'username' => "tenant{$stamp}",
            'email'    => "user{$stamp}@teste.habitaweb.local",
            'password' => $password,
            'active'   => 1,
        ]);

        if (! $userModel->save($shieldUser)) {
            throw new \RuntimeException('TenantFactory: falha ao criar usuário Shield — ' . implode(', ', $userModel->errors()));
        }
        $userId = $userModel->getInsertID();

        // account_id não é setável via Shield\Entities\User::fill() por padrão;
        // mesmo padrão usado em MainSeeder::createTestAccount() (update direto).
        \Config\Database::connect()->table('users')->where('id', $userId)->update(['account_id' => $account->id]);

        $user = $userModel->find($userId);
        $user->addGroup('user');

        return [
            'account'      => $account,
            'user'         => $user,
            'subscription' => $subscription,
            'password'     => $password,
        ];
    }

    /**
     * Gera uma API key (pk_...) para o tenant e retorna o valor em texto claro —
     * o banco só guarda hash+prefixo, então este é o único momento em que o teste
     * pode capturá-lo para montar o header Authorization: Bearer pk_...
     */
    public function createApiKey(int $accountId, int $userId, ?int $rateLimitPerHour = 1000): string
    {
        $result = (new ApiKeyModel())->generateKey($accountId, 'Chave de Teste', $userId, $rateLimitPerHour);

        if (! $result['success']) {
            throw new \RuntimeException('TenantFactory: falha ao gerar API key — ' . ($result['message'] ?? ''));
        }

        return $result['plain_key'];
    }
}
