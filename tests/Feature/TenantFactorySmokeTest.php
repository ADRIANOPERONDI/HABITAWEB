<?php

namespace Tests\Feature;

use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Teste de fumaça: valida que HabitawebTestCase + TenantFactory funcionam de ponta
 * a ponta antes de construir as suítes reais em cima delas (base de FASE 1).
 */
final class TenantFactorySmokeTest extends HabitawebTestCase
{
    public function testCreatesReadyTenantAndReachesAdminDashboard(): void
    {
        $this->seed(\App\Database\Seeds\PlanSeeder::class);

        $tenant = (new TenantFactory())->create();

        $this->assertDatabaseHas('accounts', [
            'id'                  => $tenant['account']->id,
            'verification_status' => 'APPROVED',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'account_id' => $tenant['account']->id,
            'status'     => 'ACTIVE',
        ]);

        // Prova de que a base é REAL: sobe o framework, passa pelo AdminAuth de
        // verdade, e assertOK() falha se o gate bloquear o painel.
        $result = $this->actingAs($tenant['user'])->get('admin/dashboard');
        $result->assertOK();
    }

    public function testUnauthenticatedRequestIsRedirectedToLogin(): void
    {
        $result = $this->get('admin/dashboard');
        $result->assertRedirectTo('admin/login');
    }
}
