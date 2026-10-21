<?php

namespace Tests\Feature;

use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use App\Services\PaymentService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Carência negociada concedida pelo superadmin (item 11 da revisão): só quem
 * fechou negócio recebe; o administrador escolhe plano, início e os dois
 * prazos (sem nada / só leads).
 *
 * @internal
 */
final class AccountCarenciaGrantTest extends HabitawebTestCase
{
    private function superadmin(): User
    {
        $userModel = new UserModel();
        $sufixo    = substr(uniqid(), -8);
        $userModel->save(new User([
            'username' => 'sa_car_' . $sufixo,
            'email'    => 'superadmin_car_' . $sufixo . '@teste.habitaweb.local',
            'password' => 'SuperAdminTeste#123',
            'active'   => 1,
        ]));
        $user = $userModel->find($userModel->getInsertID());
        $user->addGroup('superadmin');

        return $user;
    }

    private function diamanteId(): int
    {
        return (int) model(PlanModel::class)->where('chave', 'DIAMANTE')->first()->id;
    }

    private function ativa(int $accountId): object
    {
        return model(SubscriptionModel::class)->where('account_id', $accountId)->where('status', 'ACTIVE')->orderBy('id', 'DESC')->first();
    }

    private function concede(int $accountId, array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->superadmin())->post(
            "admin/accounts/{$accountId}/subscription/carencia",
            array_merge([csrf_token() => csrf_hash()], $data)
        );
    }

    public function testSuperadminConcedeCarenciaComPlanoEPrazos(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;

        $response = $this->concede($accountId, [
            'plan_id' => $this->diamanteId(), 'inicio' => date('Y-m-d'),
            'isencao_total_meses' => 6, 'isencao_mensalidade_meses' => 6,
        ]);

        $response->assertStatus(200);
        $sub = $this->ativa($accountId);
        $this->assertSame($this->diamanteId(), (int) $sub->plan_id);
        $this->assertSame('FREE', $sub->payment_method);
        $this->assertEquals(0.0, (float) $sub->valor);
        $this->assertSame(date('Y-m-d'), (string) $sub->ramp_started_at);
        $this->assertSame(6, (int) $sub->isencao_total_meses);
        $this->assertSame(6, (int) $sub->isencao_mensalidade_meses);
        $this->assertSame(0, (int) $sub->ramp_percent_atual);
        $this->assertNull($sub->asaas_subscription_id, 'carência não fala com o gateway');

        $json = json_decode($response->getJSON(), true);
        $this->assertStringContainsString('leads cobrados a partir de', $json['success']);
    }

    public function testRecusaContaComAssinaturaPagaNoGateway(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;
        model(SubscriptionModel::class)->where('account_id', $accountId)->set(['asaas_subscription_id' => 'sub_fake_123'])->update();

        $response = $this->concede($accountId, [
            'plan_id' => $this->diamanteId(), 'isencao_total_meses' => 6, 'isencao_mensalidade_meses' => 6,
        ]);

        $response->assertStatus(400);
        $this->assertSame('sub_fake_123', $this->ativa($accountId)->asaas_subscription_id, 'assinatura paga intacta');
    }

    public function testPrazoForaDoLimiteERecusado(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;

        $this->concede($accountId, ['plan_id' => $this->diamanteId(), 'isencao_total_meses' => 40, 'isencao_mensalidade_meses' => 6])
            ->assertStatus(400);
        $this->assertNull($this->ativa($accountId)->ramp_started_at);
    }

    public function testSoSuperadminConcede(): void
    {
        $tenant = (new TenantFactory())->create();

        $response = $this->concede((int) $tenant['account']->id, [
            'plan_id' => $this->diamanteId(), 'isencao_total_meses' => 6, 'isencao_mensalidade_meses' => 6,
        ], $tenant['user']);

        // O filtro de grupo da rota barra (redireciona) antes do controller.
        $this->assertNotSame(200, $response->response()->getStatusCode());
        $this->assertNull($this->ativa((int) $tenant['account']->id)->ramp_started_at, 'nada foi concedido');
    }

    public function testTelaDaContaMostraAsDatasDaCarencia(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;
        (new PaymentService())->grantNegotiatedTerms($accountId, $this->diamanteId(), '2026-09-05', 6, 6);

        $json = json_decode($this->actingAs($this->superadmin())->get("admin/accounts/{$accountId}/subscription")->getJSON(), true);

        $this->assertSame('2026-09-05', $json['carencia']['inicio']);
        $this->assertSame('2027-03-05', $json['carencia']['leads_a_partir']);
        $this->assertSame('2027-09-05', $json['carencia']['mensalidade_a_partir']);
    }

    /** Troca de plano a R$ 0 pelo próprio tenant não pode perder a condição negociada. */
    public function testTrocaDePlanoGratuitaMantemOsPrazos(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;
        (new PaymentService())->grantNegotiatedTerms($accountId, $this->diamanteId(), date('Y-m-d'), 3, 9);
        $ouro = model(PlanModel::class)->where('chave', 'OURO')->first();

        $atual = $this->ativa($accountId);
        (new PaymentService())->createFreeLocalSubscription($accountId, $ouro, 'MONTHLY', (string) $atual->ramp_started_at, [
            'isencao_total_meses' => $atual->isencao_total_meses, 'isencao_mensalidade_meses' => $atual->isencao_mensalidade_meses,
        ]);

        $nova = $this->ativa($accountId);
        $this->assertSame((int) $ouro->id, (int) $nova->plan_id);
        $this->assertSame(3, (int) $nova->isencao_total_meses);
        $this->assertSame(9, (int) $nova->isencao_mensalidade_meses);
    }
}
