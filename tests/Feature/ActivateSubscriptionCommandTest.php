<?php

namespace Tests\Feature;

use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * `spark assinatura:ativar` — ativa uma assinatura local gratuita (rampa de
 * lançamento por padrão) pra uma conta pelo terminal, sem passar pelo
 * checkout nem pelo gateway. Usado pra destravar conta de teste/onboarding
 * do filtro `admin_auth` ("Você precisa de uma assinatura ativa...").
 *
 * @internal
 */
final class ActivateSubscriptionCommandTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        model(PlanModel::class)->insert([
            'chave'        => 'PRATA_TESTE_ATIVAR',
            'nome'         => 'Prata Teste Ativar',
            'preco_mensal' => 990.00,
            'ativo'        => true,
        ]);
        model(PlanModel::class)->insert([
            'chave'        => 'LEGADO_TESTE_ATIVAR',
            'nome'         => 'Legado Teste Ativar',
            'preco_mensal' => 1850.00,
            'ativo'        => false,
        ]);
    }

    private function runCommand(string $args): void
    {
        ob_start();
        command('assinatura:ativar ' . $args);
        ob_end_clean();
    }

    public function testAtivaPorEmailEntraNaRampaPorDefault(): void
    {
        $tenant = (new TenantFactory())->create();

        $this->runCommand('--conta ' . $tenant['user']->email . ' --plano PRATA_TESTE_ATIVAR');

        $sub = model(SubscriptionModel::class)
            ->where('account_id', $tenant['account']->id)
            ->where('status', 'ACTIVE')
            ->first();

        $this->assertNotNull($sub);
        $this->assertSame('FREE', $sub->payment_method);
        $this->assertEquals(0.00, (float) $sub->valor);
        $this->assertNotNull($sub->ramp_started_at);
        $this->assertNull($sub->data_fim);
    }

    public function testAtivaPorIdDaConta(): void
    {
        $tenant = (new TenantFactory())->create();

        $this->runCommand('--conta ' . $tenant['account']->id . ' --plano PRATA_TESTE_ATIVAR');

        $sub = model(SubscriptionModel::class)
            ->where('account_id', $tenant['account']->id)
            ->where('status', 'ACTIVE')
            ->first();

        $this->assertNotNull($sub);
    }

    public function testSemRampaNaoGravaRampStartedAt(): void
    {
        $tenant = (new TenantFactory())->create();

        $this->runCommand('--conta ' . $tenant['user']->email . ' --plano PRATA_TESTE_ATIVAR --sem-rampa');

        $sub = model(SubscriptionModel::class)
            ->where('account_id', $tenant['account']->id)
            ->where('status', 'ACTIVE')
            ->first();

        $this->assertNull($sub->ramp_started_at);
    }

    public function testCancelaAssinaturaAnteriorAoAtivarNova(): void
    {
        $tenant = (new TenantFactory())->create([], 'PRATA_TESTE_ATIVAR');
        $antigaId = $tenant['subscription']->id;

        $this->runCommand('--conta ' . $tenant['user']->email . ' --plano PRATA_TESTE_ATIVAR');

        $antiga = model(SubscriptionModel::class)->find($antigaId);
        $this->assertSame('CANCELADA_POR_TROCA', $antiga->status);

        $ativas = model(SubscriptionModel::class)
            ->where('account_id', $tenant['account']->id)
            ->where('status', 'ACTIVE')
            ->countAllResults();
        $this->assertSame(1, $ativas);
    }

    public function testPlanoDesativadoRecusa(): void
    {
        $tenant = (new TenantFactory())->create();
        $assinaturaOriginalId = $tenant['subscription']->id;

        $this->runCommand('--conta ' . $tenant['user']->email . ' --plano LEGADO_TESTE_ATIVAR');

        // A assinatura original (do TenantFactory) continua intacta — o
        // comando recusou antes de mexer em qualquer coisa.
        $sub = model(SubscriptionModel::class)->find($assinaturaOriginalId);
        $this->assertSame('ACTIVE', $sub->status);
        $this->assertNotSame('LEGADO_TESTE_ATIVAR', model(PlanModel::class)->find($sub->plan_id)->chave);
    }

    public function testContaInexistenteNaoAlteraNada(): void
    {
        $totalAntes = model(SubscriptionModel::class)->countAllResults();

        $this->runCommand('--conta ninguem@teste.habitaweb.local --plano PRATA_TESTE_ATIVAR');

        $this->assertSame($totalAntes, model(SubscriptionModel::class)->countAllResults());
    }
}
