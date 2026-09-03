<?php

namespace Tests\Feature;

use App\Models\PlanLaunchRampModel;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\Fakes\FakePaymentGateway;
use Tests\Support\HabitawebTestCase;

/**
 * `AccountSubscriptionController::startGateway` — a ação do OPERADOR que
 * completa a virada 0%→X% que `ApplyLaunchRamp` só registra (ver docblock
 * daquela classe: é a primeira cobrança real da conta, o momento mais
 * frágil do modelo, e o comando não escolhe forma de pagamento sozinho).
 *
 * @internal
 */
final class AccountSubscriptionRampTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakePaymentGateway::$subscriptionUpdates = [];

        $db = \Config\Database::connect();
        $db->table('plan_launch_ramps')->truncate();
        model(PlanLaunchRampModel::class)->insertBatch([
            ['mes_de' => 1, 'mes_ate' => 6, 'percentual' => 0, 'is_active' => true, 'valid_from' => '2020-01-01'],
            ['mes_de' => 7, 'mes_ate' => 12, 'percentual' => 50, 'is_active' => true, 'valid_from' => '2020-01-01'],
            ['mes_de' => 13, 'mes_ate' => null, 'percentual' => 100, 'is_active' => true, 'valid_from' => '2020-01-01'],
        ]);
    }

    private function ativarGatewayFake(): void
    {
        $db = \Config\Database::connect();
        $db->query('UPDATE payment_gateways SET is_primary = false');
        $db->table('payment_gateways')->insert([
            'code'       => 'fake_' . bin2hex(random_bytes(4)),
            'name'       => 'Fake',
            'class_name' => FakePaymentGateway::class,
            'is_active'  => true,
            'is_primary' => true,
        ]);
    }

    private function superadmin(): User
    {
        $userModel = new UserModel();
        $sufixo    = substr(uniqid(), -8);

        $userModel->save(new User([
            'username' => 'sa_ramp_' . $sufixo,
            'email'    => 'superadmin_ramp_' . $sufixo . '@teste.habitaweb.local',
            'password' => 'SuperAdminTeste#123',
            'active'   => 1,
        ]));

        $superAdmin = $userModel->find($userModel->getInsertID());
        $superAdmin->addGroup('superadmin');

        return $superAdmin;
    }

    /** Conta em mês 8 da rampa (50%), pendente de cobrança no gateway. */
    private function contaPendenteDeGateway(): array
    {
        $ouroId = (int) model(PlanModel::class)->insert([
            'chave'        => 'OURO_RAMP_' . bin2hex(random_bytes(3)),
            'nome'         => 'Ouro Ramp ' . bin2hex(random_bytes(3)),
            'preco_mensal' => 1690.00,
            'ativo'        => true,
        ], true);

        $tenant = (new TenantFactory())->create();

        model(SubscriptionModel::class)->update($tenant['subscription']->id, [
            'plan_id'            => $ouroId,
            'status'             => 'ACTIVE',
            'ramp_started_at'    => date('Y-m-d', strtotime('-7 months')), // mes 8: 50%
            'ramp_percent_atual' => 50,
            'payment_method'     => 'FREE',
            'valor'              => 0.00,
            'asaas_subscription_id' => null,
        ]);

        return $tenant;
    }

    private function withCsrf(array $data = []): array
    {
        return array_merge([csrf_token() => csrf_hash()], $data);
    }

    public function testSuperadminIniciaCobrancaNoGatewayComValorDaRampa(): void
    {
        $this->ativarGatewayFake();
        $superAdmin = $this->superadmin();
        $tenant     = $this->contaPendenteDeGateway();

        $body = json_decode(
            (string) $this->actingAs($superAdmin)->post(
                'admin/accounts/' . $tenant['account']->id . '/subscription/start-gateway',
                $this->withCsrf(['billing_type' => 'PIX'])
            )->getJSON(),
            true
        );

        $this->assertArrayHasKey('success', $body, json_encode($body));

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertNotEmpty($sub->asaas_subscription_id, 'precisa ter criado a assinatura no gateway');
        $this->assertSame('PIX', $sub->payment_method);
        $this->assertSame('ACTIVE', $sub->status);
        $this->assertEquals(845.0, (float) $sub->valor, '', 0.01); // OURO 1690 * 50%
        $this->assertSame(50, (int) $sub->ramp_percent_atual);
    }

    /**
     * A rota é `group:superadmin,admin`, mas o método continua restringindo
     * a superadmin por dentro (mesmo padrão de cancel()/suspend()/upgrade()
     * nesta mesma classe) — um tenant comum não alcança de jeito nenhum.
     */
    public function testTenantNaoAlcancaARota(): void
    {
        $this->ativarGatewayFake();
        $tenant = $this->contaPendenteDeGateway();

        $this->actingAs($tenant['user'])->post(
            'admin/accounts/' . $tenant['account']->id . '/subscription/start-gateway',
            $this->withCsrf(['billing_type' => 'PIX'])
        )->assertRedirect();

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertEmpty($sub->asaas_subscription_id, 'tenant nao pode iniciar cobranca no gateway');
        $this->assertSame([], FakePaymentGateway::$subscriptionUpdates);
    }
}
