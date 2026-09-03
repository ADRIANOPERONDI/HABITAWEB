<?php

namespace Tests\Feature;

use App\Models\AccountModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * `admin/accounts/(:num)/update` (D5) — o switch "Isenta de cobrança por
 * lead" grava `cobranca_leads_isenta`, que já existe no model/entity mas
 * não tinha campo nenhum na tela nem tratamento no controller: como
 * checkbox desmarcado não é enviado no POST, sem o `!empty()` explícito
 * uma vez marcada a isenção nunca desligaria de volta pela tela.
 *
 * @internal
 */
final class AccountsFormTest extends HabitawebTestCase
{
    private function superadmin(): User
    {
        $userModel = new UserModel();
        $sufixo    = substr(uniqid(), -8);

        $userModel->save(new User([
            'username' => 'sa_contas_' . $sufixo,
            'email'    => 'superadmin_contas_' . $sufixo . '@teste.habitaweb.local',
            'password' => 'SuperAdminTeste#123',
            'active'   => 1,
        ]));

        $superAdmin = $userModel->find($userModel->getInsertID());
        $superAdmin->addGroup('superadmin');

        return $superAdmin;
    }

    private function withCsrf(array $data = []): array
    {
        return array_merge([csrf_token() => csrf_hash()], $data);
    }

    public function testIsentaContaDeCobrancaDeLeads(): void
    {
        $superAdmin = $this->superadmin();
        $tenant     = (new TenantFactory())->create();

        $this->actingAs($superAdmin)->post('admin/accounts/' . $tenant['account']->id . '/update', $this->withCsrf([
            'nome'                   => $tenant['account']->nome,
            'tipo_conta'             => $tenant['account']->tipo_conta,
            'status'                 => 'ACTIVE',
            'cobranca_leads_isenta'  => '1',
        ]));

        $conta = model(AccountModel::class)->find($tenant['account']->id);
        $this->assertTrue((bool) $conta->cobranca_leads_isenta);
    }

    public function testDesmarcarOSwitchDesligaAIsencao(): void
    {
        $superAdmin = $this->superadmin();
        $tenant     = (new TenantFactory())->create(['cobranca_leads_isenta' => true]);

        // Sem a chave no POST — exatamente o que um checkbox desmarcado envia.
        $this->actingAs($superAdmin)->post('admin/accounts/' . $tenant['account']->id . '/update', $this->withCsrf([
            'nome'       => $tenant['account']->nome,
            'tipo_conta' => $tenant['account']->tipo_conta,
            'status'     => 'ACTIVE',
        ]));

        $conta = model(AccountModel::class)->find($tenant['account']->id);
        $this->assertFalse((bool) $conta->cobranca_leads_isenta);
    }
}
