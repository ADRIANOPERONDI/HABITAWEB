<?php

namespace Tests\Feature;

use App\Database\Seeds\LeadChargeRuleSeeder;
use App\Database\Seeds\PlanSeeder;
use App\Entities\PlanFeature;
use App\Models\PlanLaunchRampModel;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\Fakes\FakePaymentGateway;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre a vitrine pública de planos (GET checkout/plans) e o processamento
 * do checkout (POST checkout/process), incluindo a rampa de lançamento (D1).
 *
 * A view lia `$plan->limite_imoveis`, `limite_fotos`, `limite_destaques` e `preco`
 * — quatro campos que não existem em `plans`. Como Entity devolve null para
 * propriedade desconhecida em vez de estourar, a página renderizava com os limites
 * em branco: o cliente via "Imóveis", "Fotos/Imóvel" e "Destaques" sem número
 * nenhum ao lado. Estes testes prendem os nomes reais das colunas.
 */
final class PlanCheckoutTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function makePlan(array $overrides = []): int
    {
        $model = model(PlanModel::class);

        $model->insert(array_merge([
            'chave'                   => 'TESTE_' . bin2hex(random_bytes(4)),
            'nome'                    => 'Plano Teste ' . bin2hex(random_bytes(4)),
            'limite_imoveis_ativos'   => 45,
            'limite_turbo_mensal'     => 10,
            'limite_fotos_por_imovel' => 12,
            'preco_mensal'            => 1850.00,
            'ativo'                   => true,
        ], $overrides));

        return (int) $model->getInsertID();
    }

    public function testListaExibeOsLimitesReaisDoPlano(): void
    {
        $this->makePlan([
            'nome'                    => 'Plano Vitrine',
            'limite_imoveis_ativos'   => 45,
            'limite_fotos_por_imovel' => 12,
            'limite_turbo_mensal'     => 10,
            'preco_mensal'            => 1850.00,
        ]);

        $response = $this->get('checkout/plans');

        $response->assertStatus(200);
        $response->assertSee('Plano Vitrine');
        $response->assertSee('1.850,00');
        $response->assertSee('45 Imóveis');
        $response->assertSee('12 Fotos/Imóvel');
        $response->assertSee('10 Turbinadas/mês');
    }

    public function testLimiteNuloAparaceComoIlimitado(): void
    {
        $this->makePlan([
            'nome'                    => 'Plano Sem Teto',
            'limite_imoveis_ativos'   => null,
            'limite_turbo_mensal'     => null,
            'limite_fotos_por_imovel' => null,
        ]);

        $response = $this->get('checkout/plans');

        $response->assertStatus(200);
        $response->assertSee('Imóveis ilimitados');
        $response->assertSee('Fotos ilimitadas');
        $response->assertSee('Turbinadas ilimitadas');
    }

    /**
     * Card do card exibe features reais do plano (filtradas por
     * `PlanFeature::visiveis()`), turbinadas + bônus anual, crédito mensal
     * de leads e o preço do ciclo anual — nada disso existia antes do D1,
     * que trocou o card estático (imóveis/fotos/"destaques") por um espelho
     * do que o plano de fato entrega.
     */
    public function testCardMostraFeaturesTurbinadasECreditoDoPlano(): void
    {
        $this->makePlan([
            'nome'                 => 'Plano Completo',
            'preco_mensal'         => 1690.00,
            'preco_anual'          => 16900.00,
            'limite_turbo_mensal'  => 5,
            'turbo_bonus_anual'    => 3,
            'credito_leads_mensal' => 200.00,
            'features'             => [PlanFeature::PAINEL_COMPLETO => true],
        ]);

        $response = $this->get('checkout/plans');

        $response->assertStatus(200);
        $response->assertSee('5 Turbinadas/mês');
        $response->assertSee('+3 turbinadas/mês no plano anual');
        $response->assertSee('Crédito mensal de R$ 200,00 em leads');
        $response->assertSee('16.900,00');
        $response->assertSee(PlanFeature::label(PlanFeature::PAINEL_COMPLETO));
    }

    /** Feature marcada `oculto` no catálogo (sem tela ainda) não aparece no card. */
    public function testFeatureOcultaNaoApareceNoCard(): void
    {
        $this->makePlan([
            'nome'     => 'Plano Com Feature Oculta',
            'features' => [PlanFeature::INTELIGENCIA_MERCADO => true],
        ]);

        $response = $this->get('checkout/plans');

        $response->assertStatus(200);
        $response->assertDontSee(PlanFeature::label(PlanFeature::INTELIGENCIA_MERCADO));
    }

    public function testMostraPrecoDeLeadDasRegrasPadrao(): void
    {
        $this->seed(LeadChargeRuleSeeder::class);
        $this->makePlan(['nome' => 'Plano Qualquer']);

        $response = $this->get('checkout/plans');

        $response->assertStatus(200);
        $response->assertSee('Venda R$ 80,00');
        $response->assertSee('Aluguel R$ 40,00');
    }

    /** A rampa é sempre mensal (P6) — a vitrine precisa dizer isso, não só mostrar o número. */
    public function testMostraRampaSoNoMensal(): void
    {
        $this->makePlan(['nome' => 'Plano Qualquer']);

        $response = $this->get('checkout/plans');

        $response->assertStatus(200);
        $response->assertSee('ciclo mensal');
    }

    public function testPlanoInativoNaoAparece(): void
    {
        $this->makePlan(['nome' => 'Plano Aposentado', 'ativo' => false]);

        $response = $this->get('checkout/plans');

        $response->assertStatus(200);
        $response->assertDontSee('Plano Aposentado');
    }

    /**
     * `PlanModel::comercializaveis()` (usado por `CheckoutController::index`)
     * exige `ativo=true` E `preco_mensal > 0` — um plano com mensalidade
     * zero (`TEST_FREE`, por exemplo) não é comercial de verdade, mesmo que
     * esteja `ativo`.
     */
    public function testPlanoGratuitoOuInativoNaoApareceNoCheckout(): void
    {
        $this->makePlan(['nome' => 'Plano Gratis Teste', 'preco_mensal' => 0.00, 'ativo' => true]);
        $this->makePlan(['nome' => 'Plano Inativo Teste', 'ativo' => false]);
        $this->makePlan(['nome' => 'Plano Comercial Teste', 'preco_mensal' => 1500.00, 'ativo' => true]);

        $response = $this->get('checkout/plans');

        $response->assertStatus(200);
        $response->assertDontSee('Plano Gratis Teste');
        $response->assertDontSee('Plano Inativo Teste');
        $response->assertSee('Plano Comercial Teste');
    }

    // --------------------------------------------------- rampa no checkout

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

    private function seedRampaMesUmZeroPorCento(): void
    {
        $db = \Config\Database::connect();
        $db->table('plan_launch_ramps')->truncate();
        model(PlanLaunchRampModel::class)->insertBatch([
            ['mes_de' => 1, 'mes_ate' => 6, 'percentual' => 0, 'is_active' => true, 'valid_from' => '2020-01-01'],
            ['mes_de' => 7, 'mes_ate' => 12, 'percentual' => 50, 'is_active' => true, 'valid_from' => '2020-01-01'],
            ['mes_de' => 13, 'mes_ate' => null, 'percentual' => 100, 'is_active' => true, 'valid_from' => '2020-01-01'],
        ]);
    }

    private function withCsrf(array $data = []): array
    {
        return array_merge([csrf_token() => csrf_hash()], $data);
    }

    /**
     * D1: todo cadastro novo pelo checkout, mensal, entra na rampa. Mês 1 é
     * 0% — o checkout precisa criar a assinatura local gratuita e NUNCA
     * chamar o gateway (Asaas não aceita assinatura de valor zero).
     */
    public function testCheckoutMensalNaRampaCriaAssinaturaGratuitaSemGateway(): void
    {
        $this->ativarGatewayFake();
        $this->seedRampaMesUmZeroPorCento();
        FakePaymentGateway::$paymentsCreated     = [];
        FakePaymentGateway::$subscriptionUpdates = [];

        $tenant = (new TenantFactory())->create([], 'PRATA');
        $plan   = model(PlanModel::class)->where('chave', 'PRATA')->first();

        $response = $this->actingAs($tenant['user'])->post('checkout/process', $this->withCsrf([
            'plan_id'       => (string) $plan->id,
            'billing_type'  => 'PIX',
            'billing_cycle' => 'MONTHLY',
        ]));

        $response->assertRedirectTo('admin/subscription');

        $novaSub = model(SubscriptionModel::class)
            ->where('account_id', $tenant['account']->id)
            ->where('status', 'ACTIVE')
            ->first();

        $this->assertNotNull($novaSub);
        $this->assertSame('FREE', $novaSub->payment_method);
        $this->assertEquals(0.00, (float) $novaSub->valor);
        $this->assertNotNull($novaSub->ramp_started_at);
        $this->assertSame(0, (int) $novaSub->ramp_percent_atual);
        $this->assertNull($novaSub->data_fim, 'assinatura gratuita nao pode ter data de expiracao');

        $this->assertSame([], FakePaymentGateway::$paymentsCreated, 'mes gratis nao pode gerar cobranca no gateway');
        $this->assertSame([], FakePaymentGateway::$subscriptionUpdates, 'mes gratis nao pode falar com o gateway');
    }

    /**
     * P6: o ciclo anual nunca entra na rampa (evita pró-rata sobre um valor
     * de 12 meses pago de uma vez, ou "anual a R$ 0"). Segue o fluxo pago
     * normal de sempre.
     */
    public function testCheckoutAnualNaoEntraNaRampa(): void
    {
        $this->ativarGatewayFake();
        $this->seedRampaMesUmZeroPorCento();
        FakePaymentGateway::$paymentsCreated = [];

        $tenant = (new TenantFactory())->create([], 'PRATA');
        $plan   = model(PlanModel::class)->where('chave', 'PRATA')->first();

        $this->actingAs($tenant['user'])->post('checkout/process', $this->withCsrf([
            'plan_id'       => (string) $plan->id,
            'billing_type'  => 'PIX',
            'billing_cycle' => 'YEARLY',
        ]));

        // payment_method != 'FREE' já exclui a assinatura original do
        // TenantFactory (payment_method NULL) — sobra só a criada agora.
        $novaSub = model(SubscriptionModel::class)
            ->where('account_id', $tenant['account']->id)
            ->where('payment_method !=', 'FREE')
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($novaSub, 'checkout anual deveria ter criado uma assinatura paga');
        $this->assertNull($novaSub->ramp_started_at, 'ciclo anual nunca entra na rampa');
        // PIX/BOLETO passam por initializeSubscription() -> createSubscription()
        // no gateway (fluxo normal) — diferente do mes gratis, que nunca
        // chega nem a pedir um customer_id.
        $this->assertNotNull($novaSub->asaas_subscription_id, 'ciclo anual segue o fluxo pago normal, com gateway');
    }
}
