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

    /**
     * A rampa de lançamento (meses sem mensalidade) é condição de negociação,
     * não oferta pública: a vitrine de planos não a anuncia nem fala em "grátis".
     */
    public function testVitrineNaoAnunciaRampaNemGratuidade(): void
    {
        $this->makePlan(['nome' => 'Plano Qualquer']);

        $response = $this->get('checkout/plans');
        $html     = $response->response()->getBody();

        $response->assertStatus(200);
        $this->assertStringNotContainsString('ciclo mensal', $html);
        $this->assertStringNotContainsStringIgnoringCase('grátis', $html);
        $this->assertStringNotContainsStringIgnoringCase('gratis', $html);
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
     * Pay to use pela plataforma (fatura única mensal): o checkout não cria
     * mais recorrência na Asaas. Gera a cobrança de ADESÃO vencendo hoje —
     * proporcional ao resto do mês —, a assinatura fica PENDING e o painel
     * só libera quando o pagamento confirma. Mesmo com faixas de rampa
     * configuradas, cadastro pelo site não ganha carência.
     */
    public function testCheckoutMensalGeraAdesaoProporcionalESoLiberaDepoisDePagar(): void
    {
        $this->ativarGatewayFake();
        $this->seedRampaMesUmZeroPorCento();
        FakePaymentGateway::$paymentsCreated = [];

        $tenant = (new TenantFactory())->create([], 'PRATA');
        $plan   = model(PlanModel::class)->where('chave', 'PRATA')->first();
        \Config\Database::connect()->table('subscriptions')->where('account_id', $tenant['account']->id)->delete();

        $this->actingAs($tenant['user'])->post('checkout/process', $this->withCsrf([
            'plan_id' => (string) $plan->id, 'billing_type' => 'PIX', 'billing_cycle' => 'MONTHLY',
        ]))->assertRedirectTo('admin/subscription');

        $this->assertCount(1, FakePaymentGateway::$paymentsCreated, 'uma cobrança avulsa, sem recorrência');
        $cobranca = FakePaymentGateway::$paymentsCreated[0];
        $restantes = (int) date('t') - (int) date('j') + 1;
        $this->assertSame(round(990 * $restantes / (int) date('t'), 2), $cobranca['amount'], 'proporcional aos dias que faltam no mês');
        $this->assertSame(date('Y-m-d'), $cobranca['data']['due_date'], 'vence hoje');
        $this->assertSame('UNDEFINED', $cobranca['data']['billing_type'], 'pagador escolhe PIX ou boleto');

        $sub = model(SubscriptionModel::class)->where('account_id', $tenant['account']->id)->orderBy('id', 'DESC')->first();
        $this->assertSame('PENDING', $sub->status);
        $this->assertNull($sub->asaas_subscription_id, 'sem recorrência nativa na Asaas');
        $this->assertNull($sub->ramp_started_at, 'cadastro pelo site não entra na carência');

        $this->actingAs($tenant['user'])->get('admin/dashboard')->assertRedirectTo('admin/subscription');

        // Pagou: assinatura ativa, mensalidade paga até o fim do mês, painel liberado.
        $tx = model(\App\Models\PaymentTransactionModel::class)->where('subscription_id', $sub->id)->first();
        $this->assertSame('ADESAO', $tx['type']);
        (new \App\Services\WebhookService())->processEvent('fake', [
            'event_type' => 'PAYMENT_CONFIRMED', 'reference_id' => $tx['gateway_transaction_id'], 'status' => 'CONFIRMED', 'data' => [],
        ]);

        $sub = model(SubscriptionModel::class)->find($sub->id);
        $this->assertSame('ACTIVE', $sub->status);
        $this->assertSame(date('Y-m-t'), substr((string) $sub->mensalidade_paga_ate, 0, 10));
        $this->assertNull($sub->data_fim);
        $this->actingAs($tenant['user'])->get('admin/dashboard')->assertOK();
    }

    /** Anual: preço anual cheio na adesão, cobrindo até o fim do 12º mês. */
    public function testCheckoutAnualCobraOAnoInteiroNaAdesao(): void
    {
        $this->ativarGatewayFake();
        FakePaymentGateway::$paymentsCreated = [];

        $tenant = (new TenantFactory())->create([], 'PRATA');
        $plan   = model(PlanModel::class)->where('chave', 'PRATA')->first();

        $this->actingAs($tenant['user'])->post('checkout/process', $this->withCsrf([
            'plan_id' => (string) $plan->id, 'billing_type' => 'BOLETO', 'billing_cycle' => 'YEARLY',
        ]));

        $this->assertSame((float) $plan->preco_anual, FakePaymentGateway::$paymentsCreated[0]['amount']);
        $tx = model(\App\Models\PaymentTransactionModel::class)->where('account_id', $tenant['account']->id)->where('type', 'ADESAO')->first();
        $meta = json_decode((string) $tx['metadata'], true);
        $this->assertSame(date('Y-m-t', strtotime(date('Y-m-01') . ' +11 months')), $meta['fim']);
    }

    /** Cartão fica para uma segunda etapa: o checkout recusa. */
    public function testCheckoutRecusaCartao(): void
    {
        $this->ativarGatewayFake();
        FakePaymentGateway::$paymentsCreated = [];
        $tenant = (new TenantFactory())->create([], 'PRATA');
        $plan   = model(PlanModel::class)->where('chave', 'PRATA')->first();

        $this->actingAs($tenant['user'])->post('checkout/process', $this->withCsrf([
            'plan_id' => (string) $plan->id, 'billing_type' => 'CREDIT_CARD', 'billing_cycle' => 'MONTHLY',
        ]));

        $this->assertSame([], FakePaymentGateway::$paymentsCreated);
    }

    /** Uma segunda tentativa de adesão não paga cancela a anterior em vez de acumular cobranças. */
    public function testNovaTentativaCancelaAdesaoAnteriorNaoPaga(): void
    {
        $this->ativarGatewayFake();
        $tenant = (new TenantFactory())->create([], 'PRATA');
        $plan   = model(PlanModel::class)->where('chave', 'PRATA')->first();

        foreach (['PIX', 'BOLETO'] as $tipo) {
            $this->actingAs($tenant['user'])->post('checkout/process', $this->withCsrf([
                'plan_id' => (string) $plan->id, 'billing_type' => $tipo, 'billing_cycle' => 'MONTHLY',
            ]));
        }

        $adesoes = model(\App\Models\PaymentTransactionModel::class)->where('account_id', $tenant['account']->id)->where('type', 'ADESAO')->orderBy('id', 'ASC')->findAll();
        $this->assertCount(2, $adesoes);
        $this->assertSame('CANCELLED', $adesoes[0]['status']);
        $this->assertSame('PENDING', $adesoes[1]['status']);
    }
}
