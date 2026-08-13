<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\LeadChargeModel;
use App\Models\LeadChargeRuleModel;
use App\Models\LeadModel;
use App\Models\PropertyExternalRefModel;
use App\Models\PropertyModel;
use App\Services\LeadChargeService;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Caminho histórico do motor de cobrança: `LeadChargeService::onLeadClosed`,
 * o gatilho de "negócio fechado" que existia antes da Fase 3 (cobrança por
 * lead RECEBIDO). Nada mais chama isto em produção desde que
 * `LeadService::updateStatus` parou de disparar o gatilho — ver
 * `LeadChargeReceivedTest` para o caminho vigente.
 *
 * Mantido: é dinheiro, e o cálculo, a precedência entre regras e a garantia
 * de não cobrar duas vezes continuam sendo o motor por trás do histórico
 * (`origem = NEGOCIO_FECHADO`) que a tela do superadmin ainda lista.
 *
 * @internal
 */
final class LeadChargeLegacyTest extends HabitawebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        cache()->clean();
    }

    /** @return array{0: array, 1: int} tenant e id do imóvel integrado */
    private function cenario(bool $integrado = true): array
    {
        $tenant = (new TenantFactory())->create();

        $propertyId = (int) model(PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Apto integrado',
            'tipo_negocio' => 'ALUGUEL',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 2000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);

        if ($integrado) {
            model(PropertyExternalRefModel::class)->insert([
                'property_id'   => $propertyId,
                'account_id'    => $tenant['account']->id,
                'provider_code' => 'simob',
                'external_id'   => '3376',
            ]);
        }

        return [$tenant, $propertyId];
    }

    private function fecharLead(array $tenant, int $propertyId, float $valor): object
    {
        $id = model(LeadModel::class)->insert([
            'property_id'           => $propertyId,
            'account_id_anunciante' => $tenant['account']->id,
            'nome_visitante'        => 'Cliente',
            'email_visitante'       => 'cliente@exemplo.com',
            'tipo_lead'             => 'MSG',
            'origem'                => 'SITE',
            'status'                => LeadModel::STATUS_CONCLUIDO,
            'closed_at'             => date('Y-m-d H:i:s'),
            'closing_value'         => $valor,
        ], true);

        return model(LeadModel::class)->find($id);
    }

    private function regra(array $attrs): int
    {
        return (int) model(LeadChargeRuleModel::class)->insert(array_merge([
            'account_id'    => null,
            'provider_code' => null,
            'tipo_negocio'  => null,
            'model'         => LeadChargeRuleModel::MODEL_PERCENT,
            'value'         => 10,
            'is_active'     => true,
        ], $attrs), true);
    }

    // -------------------------------------------------------------- apuração

    public function testApuraComissaoPercentualNoFechamento(): void
    {
        [$tenant, $propertyId] = $this->cenario();
        $this->regra(['value' => 10]);

        $lead = $this->fecharLead($tenant, $propertyId, 2400.00);

        $id = (new LeadChargeService())->onLeadClosed($lead);

        $this->assertNotNull($id);

        $c = model(LeadChargeModel::class)->find($id);

        $this->assertSame(240.0, $c->commission_value);
        $this->assertSame(2400.0, $c->base_value);
        $this->assertSame(LeadChargeModel::STATUS_PENDING, $c->status);
        $this->assertSame('ALUGUEL', $c->tipo_negocio);
    }

    public function testApuraComissaoDeValorFixo(): void
    {
        [$tenant, $propertyId] = $this->cenario();
        $this->regra(['model' => LeadChargeRuleModel::MODEL_FIXED, 'value' => 150]);

        $id = (new LeadChargeService())->onLeadClosed($this->fecharLead($tenant, $propertyId, 9000));

        $this->assertSame(150.0, model(LeadChargeModel::class)->find($id)->commission_value);
    }

    public function testPisoETetoSaoAplicados(): void
    {
        [$tenantA, $propA] = $this->cenario();
        $this->regra(['value' => 10, 'min_value' => 100, 'max_value' => 500]);

        // 10% de 300 = 30, sobe para o piso de 100.
        $idPiso = (new LeadChargeService())->onLeadClosed($this->fecharLead($tenantA, $propA, 300));
        $this->assertSame(100.0, model(LeadChargeModel::class)->find($idPiso)->commission_value);

        // 10% de 90.000 = 9.000, desce para o teto de 500.
        [$tenantB, $propB] = $this->cenario();
        $idTeto = (new LeadChargeService())->onLeadClosed($this->fecharLead($tenantB, $propB, 90000));
        $this->assertSame(500.0, model(LeadChargeModel::class)->find($idTeto)->commission_value);
    }

    /** Sem regra cadastrada não se cobra nada — silêncio, não erro. */
    public function testSemRegraNaoApuraNada(): void
    {
        [$tenant, $propertyId] = $this->cenario();

        $this->assertNull((new LeadChargeService())->onLeadClosed($this->fecharLead($tenant, $propertyId, 5000)));
        $this->assertSame(0, model(LeadChargeModel::class)->countAllResults());
    }

    /**
     * Imóvel cadastrado à mão pelo tenant é dele; cobrar comissão nele seria
     * cobrar duas vezes pelo mesmo plano.
     */
    public function testImovelNaoIntegradoNaoGeraComissao(): void
    {
        [$tenant, $propertyId] = $this->cenario(integrado: false);
        $this->regra(['value' => 10]);

        $this->assertNull((new LeadChargeService())->onLeadClosed($this->fecharLead($tenant, $propertyId, 5000)));
    }

    public function testFechamentoSemValorNaoGeraComissao(): void
    {
        [$tenant, $propertyId] = $this->cenario();
        $this->regra(['value' => 10]);

        $this->assertNull((new LeadChargeService())->onLeadClosed($this->fecharLead($tenant, $propertyId, 0)));
    }

    /** Reabrir e refechar um lead não pode cobrar duas vezes. */
    public function testMesmoLeadNaoGeraDuasComissoes(): void
    {
        [$tenant, $propertyId] = $this->cenario();
        $this->regra(['value' => 10]);

        $service = new LeadChargeService();
        $lead    = $this->fecharLead($tenant, $propertyId, 2000);

        $primeiro = $service->onLeadClosed($lead);
        $segundo  = $service->onLeadClosed($lead);

        $this->assertNotNull($primeiro);
        $this->assertNull($segundo);
        $this->assertSame(1, model(LeadChargeModel::class)->countAllResults());
    }

    /**
     * O gatilho roda dentro do fechamento do lead. Se a apuração quebrar, o
     * corretor não pode ficar impedido de fechar o negócio.
     */
    public function testFalhaNaApuracaoNaoDerrubaOFechamentoDoLead(): void
    {
        [$tenant, $propertyId] = $this->cenario();
        $this->regra(['value' => 10]);

        $leadId = (int) model(LeadModel::class)->insert([
            'property_id'           => $propertyId,
            'account_id_anunciante' => $tenant['account']->id,
            'nome_visitante'        => 'Cliente',
            'tipo_lead'             => 'MSG',
            'status'                => LeadModel::STATUS_NOVO,
        ], true);

        $ok = service('leadService')->updateStatus($leadId, LeadModel::STATUS_CONCLUIDO, ['closing_value' => 3000]);

        $this->assertTrue($ok, 'o lead tem que fechar de qualquer jeito');
        $this->assertSame(LeadModel::STATUS_CONCLUIDO, model(LeadModel::class)->find($leadId)->status);
    }

    /** O fluxo real: fechar pelo LeadService já apura. */
    public function testLeadServiceApuraSozinhoAoFechar(): void
    {
        [$tenant, $propertyId] = $this->cenario();
        $this->regra(['value' => 8]);

        $leadId = (int) model(LeadModel::class)->insert([
            'property_id'           => $propertyId,
            'account_id_anunciante' => $tenant['account']->id,
            'nome_visitante'        => 'Cliente',
            'tipo_lead'             => 'MSG',
            'status'                => LeadModel::STATUS_NOVO,
        ], true);

        service('leadService')->updateStatus($leadId, LeadModel::STATUS_CONCLUIDO, ['closing_value' => 5000]);

        $c = model(LeadChargeModel::class)->findByLead($leadId);

        $this->assertNotNull($c);
        $this->assertSame(400.0, $c->commission_value);
    }

    // ------------------------------------------------------ precedência

    /**
     * Sem ordenação por especificidade, o banco poderia devolver a regra
     * genérica antes da do tenant e cobrar a taxa errada.
     */
    public function testRegraDoTenantVenceARegraPadrao(): void
    {
        [$tenant, $propertyId] = $this->cenario();

        $this->regra(['value' => 10]);                                        // padrão
        $this->regra(['account_id' => $tenant['account']->id, 'value' => 3]); // acordo do tenant

        $id = (new LeadChargeService())->onLeadClosed($this->fecharLead($tenant, $propertyId, 1000));

        $this->assertSame(30.0, model(LeadChargeModel::class)->find($id)->commission_value);
    }

    public function testRegraPorTipoDeNegocioVenceAGenericaDoMesmoTenant(): void
    {
        [$tenant, $propertyId] = $this->cenario();

        $this->regra(['account_id' => $tenant['account']->id, 'value' => 10]);
        $this->regra(['account_id' => $tenant['account']->id, 'tipo_negocio' => 'ALUGUEL', 'value' => 5]);

        $id = (new LeadChargeService())->onLeadClosed($this->fecharLead($tenant, $propertyId, 1000));

        $this->assertSame(50.0, model(LeadChargeModel::class)->find($id)->commission_value);
    }

    public function testRegraDeOutroTipoDeNegocioNaoSeAplica(): void
    {
        [$tenant, $propertyId] = $this->cenario();

        // O imóvel é ALUGUEL; a única regra específica é de VENDA.
        $this->regra(['tipo_negocio' => 'VENDA', 'value' => 50]);
        $this->regra(['value' => 2]);

        $id = (new LeadChargeService())->onLeadClosed($this->fecharLead($tenant, $propertyId, 1000));

        $this->assertSame(20.0, model(LeadChargeModel::class)->find($id)->commission_value);
    }

    public function testRegraInativaEIgnorada(): void
    {
        [$tenant, $propertyId] = $this->cenario();

        $this->regra(['account_id' => $tenant['account']->id, 'value' => 50, 'is_active' => false]);
        $this->regra(['value' => 4]);

        $id = (new LeadChargeService())->onLeadClosed($this->fecharLead($tenant, $propertyId, 1000));

        $this->assertSame(40.0, model(LeadChargeModel::class)->find($id)->commission_value);
    }

    public function testRegraForaDaVigenciaEIgnorada(): void
    {
        [$tenant, $propertyId] = $this->cenario();

        $this->regra([
            'account_id' => $tenant['account']->id,
            'value'      => 50,
            'valid_to'   => date('Y-m-d', strtotime('-1 day')),
        ]);
        $this->regra(['value' => 6]);

        $id = (new LeadChargeService())->onLeadClosed($this->fecharLead($tenant, $propertyId, 1000));

        $this->assertSame(60.0, model(LeadChargeModel::class)->find($id)->commission_value);
    }

    // ------------------------------------------------------- ciclo de vida

    public function testAprovarECancelarSeguemOCiclo(): void
    {
        [$tenant, $propertyId] = $this->cenario();
        $this->regra(['value' => 10]);

        $service = new LeadChargeService();
        $id      = $service->onLeadClosed($this->fecharLead($tenant, $propertyId, 1000));

        $this->assertTrue($service->approve($id));
        $this->assertSame(
            LeadChargeModel::STATUS_APPROVED,
            model(LeadChargeModel::class)->find($id)->status
        );

        $this->assertTrue($service->cancel($id, 'negócio desfeito'));
        $this->assertSame(
            LeadChargeModel::STATUS_CANCELLED,
            model(LeadChargeModel::class)->find($id)->status
        );
    }

    /**
     * Comissão faturada tem cobrança no gateway atrelada: desfazer isso é
     * operação financeira, não um clique na listagem.
     */
    public function testComissaoFaturadaNaoPodeSerCancelada(): void
    {
        [$tenant, $propertyId] = $this->cenario();
        $this->regra(['value' => 10]);

        $service = new LeadChargeService();
        $id      = $service->onLeadClosed($this->fecharLead($tenant, $propertyId, 1000));

        $service->approve($id);
        $service->markInvoiced([$id], 999);

        $this->assertFalse($service->cancel($id));
        $this->assertSame(
            LeadChargeModel::STATUS_INVOICED,
            model(LeadChargeModel::class)->find($id)->status
        );
    }

    public function testFaturarSoAlcancaAsAprovadas(): void
    {
        [$tenantA, $propA] = $this->cenario();
        [$tenantB, $propB] = $this->cenario();
        $this->regra(['value' => 10]);

        $service   = new LeadChargeService();
        $aprovada  = $service->onLeadClosed($this->fecharLead($tenantA, $propA, 1000));
        $pendente  = $service->onLeadClosed($this->fecharLead($tenantB, $propB, 1000));

        $service->approve($aprovada);

        $this->assertSame(1, $service->markInvoiced([$aprovada, $pendente], 123));
        $this->assertSame(
            LeadChargeModel::STATUS_PENDING,
            model(LeadChargeModel::class)->find($pendente)->status
        );
    }

    public function testPagamentoConfirmadoMarcaAsComissoesDaFatura(): void
    {
        [$tenant, $propertyId] = $this->cenario();
        $this->regra(['value' => 10]);

        $service = new LeadChargeService();
        $id      = $service->onLeadClosed($this->fecharLead($tenant, $propertyId, 1000));

        $service->approve($id);
        $service->markInvoiced([$id], 777);

        $this->assertSame(1, $service->markPaidByTransaction(777));
        $this->assertSame(
            LeadChargeModel::STATUS_PAID,
            model(LeadChargeModel::class)->find($id)->status
        );
    }

    // --------------------------------------------------------------- telas

    public function testTenantNaoAlcancaAGestaoDeComissoes(): void
    {
        $tenant = (new TenantFactory())->create();

        $this->actingAs($tenant['user'])->get('admin/cobrancas')->assertRedirect();
    }

    public function testTenantVeOProprioExtrato(): void
    {
        [$tenant, $propertyId] = $this->cenario();
        $this->regra(['value' => 10]);
        (new LeadChargeService())->onLeadClosed($this->fecharLead($tenant, $propertyId, 1000));

        $this->actingAs($tenant['user'])->get('admin/minhas-cobrancas')->assertOK();
    }

    /** O extrato é do próprio tenant: não pode listar a comissão de outro. */
    public function testExtratoNaoVazaComissaoDeOutroTenant(): void
    {
        [$tenantA, $propA] = $this->cenario();
        $this->regra(['value' => 10]);
        (new LeadChargeService())->onLeadClosed($this->fecharLead($tenantA, $propA, 7777));

        $tenantB = (new TenantFactory())->create();

        $html = $this->actingAs($tenantB['user'])->get('admin/minhas-cobrancas')->getBody();

        $this->assertStringNotContainsString('7.777,00', $html);
    }
}
