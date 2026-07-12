<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\PaymentTransactionModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre App\Filters\AdminAuth — o bloqueador central do painel admin: KYC
 * aprovado + assinatura ACTIVE + sem fatura vencida há mais de 3 dias, com uma
 * allowlist de rotas que continuam acessíveis mesmo bloqueado. Este filtro nunca
 * tinha sido exercido por um teste real antes (ver tests/E2E/SubscriptionE2EBase,
 * que reimplementava essa lógica em vez de bater na rota de verdade).
 */
final class AdminGateTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function testAccountWithoutKycIsRedirectedToProfile(): void
    {
        $tenant = (new TenantFactory())->create(['verification_status' => 'PENDING', 'is_verified' => false]);

        $this->actingAs($tenant['user'])
            ->get('admin/dashboard')
            ->assertRedirectTo('admin/profile');
    }

    public function testAccountWithoutActiveSubscriptionIsRedirectedToSubscriptionPage(): void
    {
        $tenant = (new TenantFactory())->create();

        // Remove a assinatura ACTIVE criada pela factory para simular conta sem plano.
        \Config\Database::connect()->table('subscriptions')
            ->where('account_id', $tenant['account']->id)
            ->delete();

        $this->actingAs($tenant['user'])
            ->get('admin/dashboard')
            ->assertRedirectTo('admin/subscription');
    }

    public function testAccountWithInvoiceOverdueMoreThan3DaysIsBlocked(): void
    {
        $tenant = (new TenantFactory())->create();

        (new PaymentTransactionModel())->insert([
            'account_id' => $tenant['account']->id,
            'gateway'    => 'asaas',
            'method'     => 'PIX',
            'amount'     => 100.00,
            'status'     => 'PENDING',
            'type'       => 'SUBSCRIPTION',
            'due_date'   => date('Y-m-d', strtotime('-5 days')),
        ]);

        // Sem PaymentGatewaysSeeder, PaymentService::syncPendingPayments() faz no-op
        // (activeGateway null) — o filtro cai direto no bloqueio, sem tentar rede.
        $this->actingAs($tenant['user'])
            ->get('admin/dashboard')
            ->assertRedirectTo('admin/subscription');
    }

    public function testInvoiceOverdueLessThan3DaysDoesNotBlock(): void
    {
        $tenant = (new TenantFactory())->create();

        (new PaymentTransactionModel())->insert([
            'account_id' => $tenant['account']->id,
            'gateway'    => 'asaas',
            'method'     => 'PIX',
            'amount'     => 100.00,
            'status'     => 'PENDING',
            'type'       => 'SUBSCRIPTION',
            'due_date'   => date('Y-m-d', strtotime('-1 day')),
        ]);

        $this->actingAs($tenant['user'])
            ->get('admin/dashboard')
            ->assertOK();
    }

    /**
     * A allowlist (checkout, admin/logout, admin/profile, admin/subscription,
     * api-keys, ativacao/) precisa continuar acessível mesmo bloqueado, senão o
     * usuário nunca consegue corrigir KYC/pagamento para sair do bloqueio.
     */
    public function testProfilePageStaysReachableEvenWithoutKyc(): void
    {
        $tenant = (new TenantFactory())->create(['verification_status' => 'PENDING', 'is_verified' => false]);

        $this->actingAs($tenant['user'])
            ->get('admin/profile')
            ->assertOK();
    }

    public function testSuperAdminBypassesKycAndSubscriptionGates(): void
    {
        $userModel = new UserModel();
        $userModel->save(new User([
            'username' => 'superadmin_test',
            'email'    => 'superadmin_test@teste.habitaweb.local',
            'password' => 'SuperAdminTeste#123',
            'active'   => 1,
        ]));
        $superAdmin = $userModel->find($userModel->getInsertID());
        $superAdmin->addGroup('superadmin');

        // Sem conta/KYC/assinatura nenhuma — a prova de que é o bypass de
        // superadmin (e não a passagem pelos outros gates) que libera o acesso.
        $this->actingAs($superAdmin)
            ->get('admin/dashboard')
            ->assertOK();
    }
}
