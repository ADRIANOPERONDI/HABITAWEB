<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\HabitawebTestCase;

/**
 * `GET admin/plans` (D5) — tabela do superadmin ganha colunas de anual,
 * crédito de leads, peso de exposição e features; "Destaques" vira
 * "Turbinadas/mês" (vocabulário unificado com o resto do produto).
 *
 * @internal
 */
final class PlansAdminListTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function superadmin(): User
    {
        $userModel = new UserModel();
        $sufixo    = substr(uniqid(), -8);

        $userModel->save(new User([
            'username' => 'sa_planos_' . $sufixo,
            'email'    => 'superadmin_planos_' . $sufixo . '@teste.habitaweb.local',
            'password' => 'SuperAdminTeste#123',
            'active'   => 1,
        ]));

        $superAdmin = $userModel->find($userModel->getInsertID());
        $superAdmin->addGroup('superadmin');

        return $superAdmin;
    }

    public function testListaMostraTurbinadasCreditoEPeso(): void
    {
        $response = $this->actingAs($this->superadmin())->get('admin/plans');

        $response->assertStatus(200);
        $response->assertSee('Turbinadas/mês');
        $response->assertSee('Crédito leads');
        $response->assertSee('Peso');
        $response->assertSee('Painel completo'); // feature do OURO
    }
}
