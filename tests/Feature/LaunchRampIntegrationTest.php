<?php

namespace Tests\Feature;

use App\Models\PlanLaunchRampModel;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use App\Services\PaymentService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\Fakes\FakePaymentGateway;
use Tests\Support\HabitawebTestCase;

/**
 * A rampa de lançamento (Fase 6) integrada nos pontos reais de cobrança:
 * `SubscriptionController::upgrade()` (troca de plano pelo tenant) e
 * `PaymentService::changeSubscriptionPlan()`/`updateSubscriptionAmount()`
 * (o que realmente é cobrado no gateway). A garantia central de todos estes
 * testes: uma conta SEM `ramp_started_at` (toda conta hoje) não muda de
 * comportamento em nada — já coberto pelos 20/20 cenários de
 * `tests/E2E/Scenarios`, que continuam passando depois desta fase.
 */
final class LaunchRampIntegrationTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        FakePaymentGateway::$paymentsCreated = [];
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

    private function plan(string $chave, float $precoMensal, int $exposureWeight): int
    {
        return (int) model(PlanModel::class)->insert([
            'chave' => $chave . '_' . bin2hex(random_bytes(3)),
            'nome' => 'Plano ' . $chave . ' ' . bin2hex(random_bytes(3)),
            'preco_mensal' => $precoMensal,
            'exposure_weight' => $exposureWeight,
            'ativo' => true,
        ], true);
    }

    private function withCsrf(array $data = []): array
    {
        return array_merge([csrf_token() => csrf_hash()], $data);
    }

    public function testUpgradeDeContaNoMesUmDaRampaTomaOCaminhoGratuitoMesmoEscolhendoPlanoPago(): void
    {
        $prataId = $this->plan('PRATA', 990.00, 0);
        $ouroId  = $this->plan('OURO', 1690.00, 10);

        $tenant = (new TenantFactory())->create([], 'PRATA');
        $subModel = model(SubscriptionModel::class);
        $subModel->update($tenant['subscription']->id, [
            'plan_id' => $prataId,
            'ramp_started_at' => date('Y-m-d'),
        ]);

        $response = $this->actingAs($tenant['user'])->post('admin/subscription/upgrade/' . $ouroId, $this->withCsrf([
            'billing_type' => 'PIX',
        ]));

        $response->assertRedirectTo('admin/subscription');

        $novaSub = $subModel->where('account_id', $tenant['account']->id)->where('status', 'ACTIVE')->first();
        $this->assertSame($ouroId, (int) $novaSub->plan_id);
        $this->assertSame('FREE', $novaSub->payment_method);
        $this->assertEquals(0.00, (float) $novaSub->valor);
        $this->assertNotNull($novaSub->ramp_started_at, 'a rampa continua na assinatura nova');
        $this->assertSame(0, (int) $novaSub->ramp_percent_atual);

        $antiga = $subModel->find($tenant['subscription']->id);
        $this->assertSame('CANCELADA_POR_TROCA', $antiga->status);

        $this->assertSame([], FakePaymentGateway::$subscriptionUpdates, 'mes gratis nao deveria falar com o gateway');
    }

    public function testUpgradeDeContaNoMesUmDaRampaContinuaORelogioNaoReinicia(): void
    {
        $prataId = $this->plan('PRATA', 990.00, 0);
        $ouroId  = $this->plan('OURO', 1690.00, 10);

        $tenant = (new TenantFactory())->create([], 'PRATA');
        $rampaInicio = date('Y-m-d', strtotime('-2 months'));
        model(SubscriptionModel::class)->update($tenant['subscription']->id, [
            'plan_id' => $prataId,
            'ramp_started_at' => $rampaInicio,
        ]);

        $this->actingAs($tenant['user'])->post('admin/subscription/upgrade/' . $ouroId, $this->withCsrf([
            'billing_type' => 'PIX',
        ]));

        $novaSub = model(SubscriptionModel::class)->where('account_id', $tenant['account']->id)->where('status', 'ACTIVE')->first();
        $this->assertSame($rampaInicio, $novaSub->ramp_started_at, 'trocar de plano nao reinicia o relogio da rampa para hoje');
    }

    public function testDowngradeBloqueadoPorExposureWeightMesmoDurantePercentualNaoZeroDaRampa(): void
    {
        $ouroId   = $this->plan('OURO', 1690.00, 10);
        $prataId  = $this->plan('PRATA', 990.00, 0);

        $tenant = (new TenantFactory())->create([], 'PRATA');
        model(SubscriptionModel::class)->update($tenant['subscription']->id, [
            'plan_id' => $ouroId,
            'ramp_started_at' => date('Y-m-d', strtotime('-7 months')), // mes 8: 50%
        ]);

        $response = $this->actingAs($tenant['user'])->post('admin/subscription/upgrade/' . $prataId, $this->withCsrf([
            'billing_type' => 'PIX',
        ]));

        $response->assertRedirect();
        $ainda = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertSame($ouroId, (int) $ainda->plan_id, 'downgrade bloqueado -- plano nao mudou');
    }

    public function testChangeSubscriptionPlanAplicaODescontoDeRampaNoValorEnviadoAoGateway(): void
    {
        $this->ativarGatewayFake();

        $prataId = $this->plan('PRATA', 990.00, 0);
        $ouroId  = $this->plan('OURO', 1690.00, 10);

        $tenant = (new TenantFactory())->create([], 'PRATA');
        model(SubscriptionModel::class)->update($tenant['subscription']->id, [
            'plan_id' => $prataId,
            'asaas_subscription_id' => 'fake_sub_existente',
            'asaas_customer_id' => 'fake_cus_existente',
            'ramp_started_at' => date('Y-m-d', strtotime('-7 months')), // mes 8: 50%
        ]);

        (new PaymentService())->changeSubscriptionPlan((int) $tenant['account']->id, $ouroId, 'PIX');

        $this->assertCount(1, FakePaymentGateway::$subscriptionUpdates);
        $this->assertEquals(845.0, FakePaymentGateway::$subscriptionUpdates[0]['data']['amount'], '', 0.01);

        $sub = model(SubscriptionModel::class)->where('account_id', $tenant['account']->id)->where('status', 'ACTIVE')->first();
        $this->assertEquals(845.0, (float) $sub->valor);
        $this->assertSame(50, (int) $sub->ramp_percent_atual);
    }

    public function testChangeSubscriptionPlanSemRampaContinuaCobrandoOPrecoCheio(): void
    {
        $this->ativarGatewayFake();

        $prataId = $this->plan('PRATA', 990.00, 0);
        $ouroId  = $this->plan('OURO', 1690.00, 10);

        $tenant = (new TenantFactory())->create([], 'PRATA');
        model(SubscriptionModel::class)->update($tenant['subscription']->id, [
            'plan_id' => $prataId,
            'asaas_subscription_id' => 'fake_sub_existente',
            'asaas_customer_id' => 'fake_cus_existente',
        ]);

        (new PaymentService())->changeSubscriptionPlan((int) $tenant['account']->id, $ouroId, 'PIX');

        $this->assertEquals(1690.0, FakePaymentGateway::$subscriptionUpdates[0]['data']['amount'], '', 0.01);
    }

    public function testUpdateSubscriptionAmountAtualizaOGatewayEOValorLocal(): void
    {
        $this->ativarGatewayFake();

        $tenant = (new TenantFactory())->create();
        model(SubscriptionModel::class)->update($tenant['subscription']->id, [
            'asaas_subscription_id' => 'fake_sub_xyz',
        ]);

        $ok = (new PaymentService())->updateSubscriptionAmount((int) $tenant['subscription']->id, 1690.0, 100);

        $this->assertTrue($ok);
        $this->assertCount(1, FakePaymentGateway::$subscriptionUpdates);
        $this->assertSame('fake_sub_xyz', FakePaymentGateway::$subscriptionUpdates[0]['subscription_id']);
        $this->assertEquals(1690.0, FakePaymentGateway::$subscriptionUpdates[0]['data']['amount'], '', 0.01);

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertEquals(1690.0, (float) $sub->valor);
        $this->assertSame(100, (int) $sub->ramp_percent_atual);
    }

    public function testUpdateSubscriptionAmountFalhaSemVinculoNoGateway(): void
    {
        $this->ativarGatewayFake();

        $tenant = (new TenantFactory())->create();

        $this->expectException(\Exception::class);
        (new PaymentService())->updateSubscriptionAmount((int) $tenant['subscription']->id, 990.0);
    }

    public function testPreviewUpgradeDevolveOPrecoComDescontoDeRampa(): void
    {
        $prataId = $this->plan('PRATA', 990.00, 0);
        $ouroId  = $this->plan('OURO', 1690.00, 10);

        $tenant = (new TenantFactory())->create([], 'PRATA');
        model(SubscriptionModel::class)->update($tenant['subscription']->id, [
            'plan_id' => $prataId,
            'ramp_started_at' => date('Y-m-d', strtotime('-7 months')), // mes 8: 50%
        ]);

        $json = json_decode(
            $this->actingAs($tenant['user'])->get('admin/subscription/preview-upgrade/' . $ouroId)->getJSON(),
            true
        );

        $this->assertEquals(845.0, $json['new_price'], '', 0.01);
    }
}
