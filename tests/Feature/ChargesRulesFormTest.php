<?php

namespace Tests\Feature;

use App\Models\LeadChargeRuleModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\HabitawebTestCase;

/**
 * Formulário de regras de cobrança (Admin/cobrancas/rules.php) batendo na
 * rota de verdade. `ChargesController::saveRule()` já tratava `id` para
 * decidir update x insert — o que faltava era o form conseguir MANDAR um id
 * (botão "editar") e ter campos de vigência, que até então só entravam via
 * `UPDATE` manual no banco.
 *
 * @internal
 */
final class ChargesRulesFormTest extends HabitawebTestCase
{
    private function withCsrf(array $data = []): array
    {
        return array_merge([csrf_token() => csrf_hash()], $data);
    }

    private function superadmin(): User
    {
        $userModel = new UserModel();
        // username é varchar(30) no Shield: uniqid() sozinho já teria 13
        // caracteres, e um prefixo descritivo completo estourava o limite.
        $sufixo = substr(uniqid(), -8);

        $userModel->save(new User([
            'username' => 'sa_regras_' . $sufixo,
            'email'    => 'superadmin_regras_' . $sufixo . '@teste.habitaweb.local',
            'password' => 'SuperAdminTeste#123',
            'active'   => 1,
        ]));

        $superAdmin = $userModel->find($userModel->getInsertID());
        $superAdmin->addGroup('superadmin');

        return $superAdmin;
    }

    public function testSalvaRegraComVigencia(): void
    {
        $superAdmin = $this->superadmin();

        $this->actingAs($superAdmin)->post('admin/cobrancas/regras', $this->withCsrf([
            'account_id'   => '',
            'tipo_negocio' => 'VENDA',
            'model'        => 'FIXED',
            'value'        => '80',
            'valid_from'   => '2026-09-01',
            'valid_to'     => '2026-12-31',
            'is_active'    => '1',
        ]))->assertRedirect();

        $rule = model(LeadChargeRuleModel::class)
            ->where('account_id', null)
            ->where('tipo_negocio', 'VENDA')
            ->first();

        $this->assertNotNull($rule);
        $this->assertSame('2026-09-01', substr((string) $rule->valid_from, 0, 10));
        $this->assertSame('2026-12-31', substr((string) $rule->valid_to, 0, 10));
        // FIXED não usa piso/teto — o valor já é o único número da regra.
        $this->assertNull($rule->min_value);
        $this->assertNull($rule->max_value);
    }

    /**
     * O clique em "editar" preenche o `<input hidden name="id">` com o id da
     * linha; sem isso, salvar de novo sempre criava uma regra nova em vez de
     * atualizar a existente.
     */
    public function testEditaRegraExistente(): void
    {
        $superAdmin = $this->superadmin();
        $ruleModel  = model(LeadChargeRuleModel::class);

        $id = $ruleModel->insert([
            'account_id'   => null,
            'tipo_negocio' => 'ALUGUEL',
            'model'        => 'FIXED',
            'value'        => 40.00,
            'is_active'    => true,
        ], true);

        $this->actingAs($superAdmin)->post('admin/cobrancas/regras', $this->withCsrf([
            'id'           => (string) $id,
            'account_id'   => '',
            'tipo_negocio' => 'ALUGUEL',
            'model'        => 'FIXED',
            'value'        => '45',
            'is_active'    => '1',
        ]))->assertRedirect();

        $this->assertSame(
            1,
            $ruleModel->where('tipo_negocio', 'ALUGUEL')->countAllResults(),
            'editar não pode criar uma segunda linha'
        );

        $atualizada = $ruleModel->find($id);
        $this->assertSame(45.0, (float) $atualizada->value);
    }
}
