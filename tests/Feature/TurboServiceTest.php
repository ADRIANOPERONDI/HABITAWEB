<?php

namespace Tests\Feature;

use App\Database\Seeds\PromotionPackageSeeder;
use App\Models\PlanModel;
use App\Models\PromotionModel;
use App\Models\PropertyModel;
use App\Models\SubscriptionModel;
use App\Services\PlanGate;
use App\Services\TurboService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre TurboService: cota mensal, ativação pela cota, idempotência do webhook
 * pago e limpeza de vencidos.
 *
 * `TURBO_7_DIAS` é resolvido pelo próprio seeder de produção
 * (`PromotionPackageSeeder`), não recriado à mão — assim o teste quebra se o
 * pacote real mudar de característica (duração, tipo) em vez de continuar
 * verde contra um fixture desatualizado.
 */
final class TurboServiceTest extends HabitawebTestCase
{
    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        PlanGate::flushMemo();
        cache()->clean();

        $this->seed(PromotionPackageSeeder::class);

        $tenant = (new TenantFactory())->create();
        $this->accountId = (int) $tenant['account']->id;
    }

    private function makePlanComTurbo(?int $limiteTurbo): int
    {
        $model = model(PlanModel::class);
        $model->insert([
            'chave'               => 'TB_' . bin2hex(random_bytes(4)),
            'nome'                => 'Plano Turbo ' . bin2hex(random_bytes(4)),
            'preco_mensal'        => 990.00,
            'limite_turbo_mensal' => $limiteTurbo,
            'ativo'               => true,
        ]);

        return (int) $model->getInsertID();
    }

    private function assinar(int $planId): void
    {
        model(SubscriptionModel::class)
            ->where('account_id', $this->accountId)
            ->set(['plan_id' => $planId, 'status' => 'ACTIVE'])
            ->update();

        PlanGate::forget($this->accountId);
    }

    private function novoImovel(): int
    {
        $model = new PropertyModel();
        $model->insert([
            'account_id'   => $this->accountId,
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'apartamento',
            'titulo'       => 'Imóvel para turbo',
            'cidade'       => 'São Paulo',
            'bairro'       => 'Centro',
            'preco'        => 500000,
            'status'       => 'ACTIVE',
        ]);

        return (int) $model->getInsertID();
    }

    // ---- quotaFor -----------------------------------------------------

    public function testContaSemAssinaturaTemCotaZero(): void
    {
        $quota = (new TurboService())->quotaFor($this->accountId);

        $this->assertSame(0, $quota['incluidas']);
        $this->assertSame(0, $quota['restantes']);
    }

    public function testPlanoComZeroTurbosBloqueiaTudo(): void
    {
        $this->assinar($this->makePlanComTurbo(0));

        $quota = (new TurboService())->quotaFor($this->accountId);

        $this->assertSame(0, $quota['incluidas']);
        $this->assertSame(0, $quota['restantes']);
    }

    public function testPlanoIlimitadoNaoTemRestantesNumerico(): void
    {
        $this->assinar($this->makePlanComTurbo(null));

        $quota = (new TurboService())->quotaFor($this->accountId);

        $this->assertNull($quota['incluidas']);
        $this->assertNull(
            $quota['restantes'],
            'Regressão do bug antigo: (limite ?? 0) <= 0 bloqueava justamente o plano ilimitado.'
        );
    }

    // ---- activateFromQuota ---------------------------------------------

    public function testConsomeACotaAtivandoTurbinada(): void
    {
        $this->assinar($this->makePlanComTurbo(5));
        $propertyId = $this->novoImovel();

        $service = new TurboService();
        $result  = $service->activateFromQuota($propertyId, $this->accountId);

        $this->assertTrue($result['success'], $result['message']);

        $property = (new PropertyModel())->find($propertyId);
        $this->assertSame(1, (int) $property->highlight_level);
        $this->assertNotNull($property->highlight_expires_at);

        $quota = $service->quotaFor($this->accountId);
        $this->assertSame(1, $quota['usadas']);
        $this->assertSame(4, $quota['restantes']);
    }

    public function testBloqueiaAoEsgotarACota(): void
    {
        $this->assinar($this->makePlanComTurbo(2));
        $service = new TurboService();

        $this->assertTrue($service->activateFromQuota($this->novoImovel(), $this->accountId)['success']);
        $this->assertTrue($service->activateFromQuota($this->novoImovel(), $this->accountId)['success']);

        $terceiro = $service->activateFromQuota($this->novoImovel(), $this->accountId);

        $this->assertFalse($terceiro['success']);
        $this->assertStringContainsString('2 turbinadas incluídas', $terceiro['message']);
    }

    public function testPlanoIlimitadoConsomeSemBloquear(): void
    {
        $this->assinar($this->makePlanComTurbo(null));
        $service = new TurboService();

        for ($i = 0; $i < 20; $i++) {
            $result = $service->activateFromQuota($this->novoImovel(), $this->accountId);
            $this->assertTrue($result['success'], "Falhou na iteração {$i}: {$result['message']}");
        }

        $this->assertSame(20, $service->quotaFor($this->accountId)['usadas']);
    }

    public function testNaoTurbinaImovelDeOutraConta(): void
    {
        $this->assinar($this->makePlanComTurbo(5));

        $outraConta = (new TenantFactory())->create();
        $imovelAlheio = (new PropertyModel())->insert([
            'account_id'   => (int) $outraConta['account']->id,
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'apartamento',
            'titulo'       => 'Imóvel de outra conta',
            'cidade'       => 'São Paulo',
            'bairro'       => 'Centro',
            'preco'        => 500000,
            'status'       => 'ACTIVE',
        ]);
        $imovelAlheioId = (int) (new PropertyModel())->getInsertID();

        $result = (new TurboService())->activateFromQuota($imovelAlheioId, $this->accountId);

        $this->assertFalse($result['success']);
    }

    public function testVirarDeMesLiberaACotaDeNovo(): void
    {
        $this->assinar($this->makePlanComTurbo(1));
        $service = new TurboService();

        $this->assertTrue($service->activateFromQuota($this->novoImovel(), $this->accountId)['success']);
        $this->assertFalse($service->activateFromQuota($this->novoImovel(), $this->accountId)['success']);

        // Simula "mês passado": recua a única promoção consumida um mês.
        model(PromotionModel::class)
            ->where('account_id', $this->accountId)
            ->set(['periodo' => date('Y-m-01', strtotime('-1 month'))])
            ->update();

        $result = $service->activateFromQuota($this->novoImovel(), $this->accountId);

        $this->assertTrue($result['success'], 'A cota do mês novo não pode ficar presa pelo consumo do mês anterior.');
    }

    // ---- activatePaid / idempotência do webhook -------------------------

    public function testAtivaTurbinadaPagaComONivelCorreto(): void
    {
        $propertyId = $this->novoImovel();

        $ok = (new TurboService())->activatePaid($propertyId, 'TURBO_7_DIAS', 900001);

        $this->assertTrue($ok);

        $property = (new PropertyModel())->find($propertyId);
        $this->assertSame(1, (int) $property->highlight_level);

        $promo = model(PromotionModel::class)->where('payment_transaction_id', 900001)->first();
        $this->assertNotNull($promo);
        $this->assertSame('PAGO', $promo->origem);
    }

    public function testWebhookRepetidoNaoDuplicaAtivacao(): void
    {
        $propertyId = $this->novoImovel();
        $service    = new TurboService();

        $this->assertTrue($service->activatePaid($propertyId, 'TURBO_7_DIAS', 900002));
        $this->assertTrue(
            $service->activatePaid($propertyId, 'TURBO_7_DIAS', 900002),
            'Replay do webhook precisa retornar sucesso (idempotente), não erro.'
        );

        $this->assertSame(
            1,
            model(PromotionModel::class)->where('payment_transaction_id', 900002)->countAllResults(),
            'Webhook repetido não pode criar uma segunda linha de promoção.'
        );
    }

    public function testPacoteDeLeadNaoAtivaTurbo(): void
    {
        $propertyId = $this->novoImovel();

        $ok = (new TurboService())->activatePaid($propertyId, 'LEAD_COMPRA', 900003);

        $this->assertFalse($ok);
        $this->assertSame(
            0,
            (int) (new PropertyModel())->find($propertyId)->highlight_level
        );
    }

    // ---- deactivateExpired ------------------------------------------------

    public function testDeactivateExpiredZeraDestaqueVencido(): void
    {
        $propertyId = $this->novoImovel();

        model(PromotionModel::class)->insert([
            'property_id'   => $propertyId,
            'account_id'    => $this->accountId,
            'tipo_promocao' => 'TURBO_IMOVEL',
            'origem'        => 'PAGO',
            'periodo'       => date('Y-m-01'),
            'data_inicio'   => date('Y-m-d H:i:s', strtotime('-10 days')),
            'data_fim'      => date('Y-m-d H:i:s', strtotime('-3 days')),
            'ativo'         => true,
        ]);
        (new PropertyModel())->update($propertyId, [
            'highlight_level'      => 1,
            'highlight_expires_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
        ]);

        (new TurboService())->deactivateExpired();

        $property = (new PropertyModel())->find($propertyId);
        $this->assertSame(0, (int) $property->highlight_level);
        $this->assertNull($property->highlight_expires_at);

        $promo = model(PromotionModel::class)->where('property_id', $propertyId)->first();
        $this->assertFalse((bool) $promo->ativo);
    }
}
