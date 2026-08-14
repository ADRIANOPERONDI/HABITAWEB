<?php

namespace Tests\Feature;

use App\Models\AuditLogModel;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\Fakes\FakePaymentGateway;
use Tests\Support\HabitawebTestCase;

/**
 * `spark planos:migrar-comercial` — move contas de plano legado (`*_LEGADO`,
 * criado por `PlanSeeder::renomearLegados()`) para o plano comercial novo.
 * As duas garantias mais importantes: o modo escolhido (`--modo`) é sempre
 * explícito (nunca um default silencioso, porque é decisão de caixa do
 * cliente), e `--modo rampa` sobre uma conta que já paga de verdade no
 * gateway CANCELA a assinatura real em vez de tentar deixá-la em R$0 (Asaas
 * não aceita assinatura de valor zero).
 */
final class MigrateCommercialPlansCommandTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakePaymentGateway::$subscriptionUpdates = [];
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

    /** @return array{legado:int, novo:int} */
    private function planoLegadoENovo(float $precoLegado, float $precoNovo): array
    {
        $sufixo = bin2hex(random_bytes(3));
        $legadoId = (int) model(PlanModel::class)->insert([
            'chave' => "TESTE{$sufixo}_LEGADO",
            'nome' => "Teste {$sufixo} (legado)",
            'preco_mensal' => $precoLegado,
            'ativo' => false,
        ], true);

        $novoId = (int) model(PlanModel::class)->insert([
            'chave' => "TESTE{$sufixo}",
            'nome' => "Teste {$sufixo}",
            'preco_mensal' => $precoNovo,
            'ativo' => true,
        ], true);

        return ['legado' => $legadoId, 'novo' => $novoId];
    }

    private function contaNoLegado(int $planoLegadoId, array $overrides = []): array
    {
        $tenant = (new TenantFactory())->create();
        model(SubscriptionModel::class)->update($tenant['subscription']->id, array_merge([
            'plan_id' => $planoLegadoId,
        ], $overrides));

        return $tenant;
    }

    private function runCommand(string $args): void
    {
        ob_start();
        command('planos:migrar-comercial ' . $args);
        ob_end_clean();
    }

    public function testDryRunNaoGravaNada(): void
    {
        $planos = $this->planoLegadoENovo(1850.00, 990.00);
        $tenant = $this->contaNoLegado($planos['legado']);

        $this->runCommand('--dry-run');

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertSame($planos['legado'], (int) $sub->plan_id, 'dry-run nao muda o plano');
        $this->assertSame(0, model(AuditLogModel::class)->where('account_id', $tenant['account']->id)->countAllResults());
    }

    public function testExigirDryRunOuConfirmarExclusivamente(): void
    {
        $planos = $this->planoLegadoENovo(1850.00, 990.00);
        $tenant = $this->contaNoLegado($planos['legado']);

        $this->runCommand(''); // nenhum dos dois

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertSame($planos['legado'], (int) $sub->plan_id);
    }

    public function testConfirmarSemModoNaoAplicaNada(): void
    {
        $planos = $this->planoLegadoENovo(1850.00, 990.00);
        $tenant = $this->contaNoLegado($planos['legado']);

        $this->runCommand('--confirmar');

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertSame($planos['legado'], (int) $sub->plan_id, '--confirmar sem --modo nao pode aplicar nada');
    }

    public function testModoRampaSemAssinaturaNoGatewayApenasTrocaOPlanoEIniciaARampa(): void
    {
        $planos = $this->planoLegadoENovo(1850.00, 990.00);
        $tenant = $this->contaNoLegado($planos['legado']);

        $this->runCommand('--confirmar --modo rampa');

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertSame($planos['novo'], (int) $sub->plan_id);
        $this->assertSame(date('Y-m-d'), $sub->ramp_started_at);
        $this->assertSame(0, (int) $sub->ramp_percent_atual);
        $this->assertEquals(0.0, (float) $sub->valor);
        $this->assertSame('FREE', $sub->payment_method);

        $log = model(AuditLogModel::class)->where('account_id', $tenant['account']->id)->first();
        $this->assertSame('plano.migrado_comercial', $log->action);
    }

    public function testModoRampaComAssinaturaNoGatewayCancelaAAssinaturaReal(): void
    {
        $this->ativarGatewayFake();
        $planos = $this->planoLegadoENovo(1850.00, 990.00);
        $tenant = $this->contaNoLegado($planos['legado'], [
            'asaas_subscription_id' => 'fake_sub_ativa',
            'asaas_customer_id' => 'fake_cus_ativa',
        ]);

        $this->runCommand('--confirmar --modo rampa');

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertSame($planos['novo'], (int) $sub->plan_id);
        $this->assertNull($sub->asaas_subscription_id, 'assinatura cancelada -- vinculo local removido');
        $this->assertNotNull($sub->asaas_customer_id, 'o cliente no gateway continua valido, so a assinatura foi cancelada');
        $this->assertSame(0, (int) $sub->ramp_percent_atual);

        // Nao deveria ter chamado updateSubscription (nao tenta deixar em R$0)
        $this->assertSame([], FakePaymentGateway::$subscriptionUpdates);
    }

    public function testModoCheioComAssinaturaNoGatewayAtualizaOValorSemCancelar(): void
    {
        $this->ativarGatewayFake();
        $planos = $this->planoLegadoENovo(1850.00, 990.00);
        $tenant = $this->contaNoLegado($planos['legado'], [
            'asaas_subscription_id' => 'fake_sub_ativa',
            'asaas_customer_id' => 'fake_cus_ativa',
        ]);

        $this->runCommand('--confirmar --modo cheio');

        $sub = model(SubscriptionModel::class)->find($tenant['subscription']->id);
        $this->assertSame($planos['novo'], (int) $sub->plan_id);
        $this->assertSame('fake_sub_ativa', $sub->asaas_subscription_id, 'nao cancela no modo cheio');
        $this->assertEquals(990.0, (float) $sub->valor);

        $this->assertCount(1, FakePaymentGateway::$subscriptionUpdates);
        $this->assertEquals(990.0, FakePaymentGateway::$subscriptionUpdates[0]['data']['amount'], '', 0.01);
    }

    public function testFiltroPorContaRestringeAUmaUnicaAssinatura(): void
    {
        $planos = $this->planoLegadoENovo(1850.00, 990.00);
        $tenantA = $this->contaNoLegado($planos['legado']);
        $tenantB = $this->contaNoLegado($planos['legado']);

        $this->runCommand('--confirmar --modo cheio --conta ' . $tenantA['account']->id);

        $subA = model(SubscriptionModel::class)->find($tenantA['subscription']->id);
        $subB = model(SubscriptionModel::class)->find($tenantB['subscription']->id);

        $this->assertSame($planos['novo'], (int) $subA->plan_id);
        $this->assertSame($planos['legado'], (int) $subB->plan_id, 'conta B nao foi tocada');
    }

    public function testSemPlanosLegadosNaoQuebra(): void
    {
        $db = \Config\Database::connect();
        $db->table('plans')->like('chave', '_LEGADO')->delete();

        $this->runCommand('--dry-run');
        $this->runCommand('--confirmar --modo rampa');

        $this->assertTrue(true, 'nao deveria lancar excecao');
    }
}
