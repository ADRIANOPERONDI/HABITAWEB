<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\AccountModel;
use App\Models\LeadChargeModel;
use App\Models\LeadChargeRuleModel;
use App\Models\LeadModel;
use App\Models\PropertyExternalRefModel;
use App\Models\PropertyModel;
use App\Services\LeadChargeService;
use App\Services\LeadService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobrança por lead RECEBIDO — o gatilho vigente a partir da Fase 3.
 *
 * Todo lead recebido é cobrável, integrado ou não (o gate de
 * property_external_refs do motor antigo desapareceu). O que decide se uma
 * cobrança nasce é: ter regra configurada, a conta não estar isenta, e o
 * valor calculado ser positivo.
 */
final class LeadChargeReceivedTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        cache()->clean();
    }

    private function regra(array $attrs): int
    {
        return (int) model(LeadChargeRuleModel::class)->insert(array_merge([
            'account_id'    => null,
            'provider_code' => null,
            'tipo_negocio'  => null,
            'model'         => LeadChargeRuleModel::MODEL_FIXED,
            'value'         => 80,
            'is_active'     => true,
        ], $attrs), true);
    }

    private function property(int $accountId, string $tipoNegocio = 'VENDA'): int
    {
        return (int) model(PropertyModel::class)->insert([
            'account_id'   => $accountId,
            'titulo'       => 'Imovel Cobranca Lead',
            'tipo_negocio' => $tipoNegocio,
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 500000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);
    }

    private function lead(int $accountId, int $propertyId, string $tipoNegocio, array $overrides = []): object
    {
        $id = model(LeadModel::class)->insert(array_merge([
            'property_id'           => $propertyId,
            'account_id_anunciante' => $accountId,
            'nome_visitante'        => 'Visitante',
            'email_visitante'       => 'visitante_' . bin2hex(random_bytes(4)) . '@teste.local',
            'tipo_lead'             => 'MSG',
            'origem'                => 'SITE',
            'status'                => LeadModel::STATUS_NOVO,
            'tipo_negocio'          => $tipoNegocio,
        ], $overrides), true);

        return model(LeadModel::class)->find($id);
    }

    // ------------------------------------------------------------- apuração

    public function testGeraCobrancaFixaAoReceberLeadDeVenda(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->regra(['tipo_negocio' => 'VENDA', 'value' => 80]);
        $this->regra(['tipo_negocio' => 'ALUGUEL', 'value' => 40]);

        $propertyId = $this->property($tenant['account']->id, 'VENDA');
        $lead       = $this->lead($tenant['account']->id, $propertyId, 'VENDA');

        $id = (new LeadChargeService())->onLeadReceived($lead);

        $this->assertNotNull($id);

        $charge = model(LeadChargeModel::class)->find($id);

        $this->assertSame(80.0, $charge->commission_value);
        $this->assertSame(0.0, $charge->base_value);
        $this->assertSame(LeadChargeModel::STATUS_PENDING, $charge->status);
        $this->assertSame(LeadChargeModel::ORIGEM_LEAD_RECEBIDO, $charge->origem);
        $this->assertSame(date('Y-m-01'), $charge->periodo);
        $this->assertNotNull($charge->contest_deadline);
        $this->assertGreaterThan(time(), strtotime((string) $charge->contest_deadline));
    }

    public function testValorDeAluguelUsaARegraDeAluguel(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->regra(['tipo_negocio' => 'VENDA', 'value' => 80]);
        $this->regra(['tipo_negocio' => 'ALUGUEL', 'value' => 40]);

        $propertyId = $this->property($tenant['account']->id, 'ALUGUEL');
        $lead       = $this->lead($tenant['account']->id, $propertyId, 'ALUGUEL');

        $id = (new LeadChargeService())->onLeadReceived($lead);

        $this->assertSame(40.0, model(LeadChargeModel::class)->find($id)->commission_value);
    }

    /**
     * Decisão do cliente: imóvel anunciado pras duas modalidades ao mesmo
     * tempo (raro) cobra como VENDA (R$80), não como ALUGUEL (R$40).
     */
    public function testVendaAluguelUsaPrecoDeVenda(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->regra(['tipo_negocio' => 'ALUGUEL', 'value' => 40]);
        $this->regra(['tipo_negocio' => 'VENDA', 'value' => 80]);

        $propertyId = $this->property($tenant['account']->id, 'VENDA_ALUGUEL');
        $lead       = $this->lead($tenant['account']->id, $propertyId, 'VENDA_ALUGUEL');

        $id = (new LeadChargeService())->onLeadReceived($lead);

        $this->assertSame(80.0, model(LeadChargeModel::class)->find($id)->commission_value);
    }

    /** O gate de property_external_refs do motor antigo não existe mais aqui. */
    public function testImovelNaoIntegradoTambemGeraCobranca(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->regra(['tipo_negocio' => 'VENDA', 'value' => 80]);

        $propertyId = $this->property($tenant['account']->id, 'VENDA');
        // Sem property_external_refs — imóvel cadastrado à mão pelo tenant.
        $lead = $this->lead($tenant['account']->id, $propertyId, 'VENDA');

        $this->assertNotNull((new LeadChargeService())->onLeadReceived($lead));
    }

    /** Imóvel integrado também cobra, e a regra por conector ainda tem precedência. */
    public function testImovelIntegradoUsaRegraDoConector(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->regra(['tipo_negocio' => 'VENDA', 'value' => 80]);
        $this->regra(['tipo_negocio' => 'VENDA', 'provider_code' => 'simob', 'value' => 120]);

        $propertyId = $this->property($tenant['account']->id, 'VENDA');
        model(PropertyExternalRefModel::class)->insert([
            'property_id'   => $propertyId,
            'account_id'    => $tenant['account']->id,
            'provider_code' => 'simob',
            'external_id'   => '999',
        ]);
        $lead = $this->lead($tenant['account']->id, $propertyId, 'VENDA');

        $id = (new LeadChargeService())->onLeadReceived($lead);

        $this->assertSame(120.0, model(LeadChargeModel::class)->find($id)->commission_value);
    }

    public function testSemRegraNaoGeraCobranca(): void
    {
        $tenant     = (new TenantFactory())->create();
        $propertyId = $this->property($tenant['account']->id, 'VENDA');
        $lead       = $this->lead($tenant['account']->id, $propertyId, 'VENDA');

        $this->assertNull((new LeadChargeService())->onLeadReceived($lead));
        $this->assertSame(0, model(LeadChargeModel::class)->countAllResults());
    }

    public function testContaIsentaNaoGeraCobranca(): void
    {
        $tenant = (new TenantFactory())->create();
        model(AccountModel::class)->update($tenant['account']->id, ['cobranca_leads_isenta' => true]);
        $this->regra(['tipo_negocio' => 'VENDA', 'value' => 80]);

        $propertyId = $this->property($tenant['account']->id, 'VENDA');
        $lead       = $this->lead($tenant['account']->id, $propertyId, 'VENDA');

        $this->assertNull((new LeadChargeService())->onLeadReceived($lead));
    }

    /** Um lead gera no máximo uma cobrança — unique em lead_id como rede de segurança. */
    public function testMesmoLeadNaoGeraDuasCobrancas(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->regra(['tipo_negocio' => 'VENDA', 'value' => 80]);

        $propertyId = $this->property($tenant['account']->id, 'VENDA');
        $lead       = $this->lead($tenant['account']->id, $propertyId, 'VENDA');

        $service  = new LeadChargeService();
        $primeiro = $service->onLeadReceived($lead);
        $segundo  = $service->onLeadReceived($lead);

        $this->assertNotNull($primeiro);
        $this->assertNull($segundo);
        $this->assertSame(1, model(LeadChargeModel::class)->countAllResults());
    }

    // -------------------------------------------------------- fluxo real

    /** O caminho real: registrar o lead pelo LeadService já cobra, snapshot incluído. */
    public function testLeadServiceCobraSozinhoAoReceber(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->regra(['tipo_negocio' => 'ALUGUEL', 'value' => 40]);

        $propertyId = $this->property($tenant['account']->id, 'ALUGUEL');

        $result = (new LeadService())->trySaveLead([
            'property_id'      => $propertyId,
            'nome_visitante'   => 'Visitante Real',
            'email_visitante'  => 'visitante_real_' . bin2hex(random_bytes(4)) . '@teste.local',
            'mensagem'         => 'Tenho interesse',
        ]);

        $this->assertTrue($result['success']);

        $leadId = (int) $result['data']->id;
        $charge = model(LeadChargeModel::class)->findByLead($leadId);

        $this->assertNotNull($charge, 'o recebimento do lead tem que cobrar sozinho');
        $this->assertSame(40.0, $charge->commission_value);

        $savedLead = model(LeadModel::class)->find($leadId);
        $this->assertSame('ALUGUEL', $savedLead->tipo_negocio, 'tipo_negocio precisa vir snapshotado no lead');
    }

    /** Lead deduplicado (mesmo e-mail, mesmo imóvel) não gera uma segunda cobrança. */
    public function testLeadDeduplicadoNaoCobraDeNovo(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->regra(['tipo_negocio' => 'VENDA', 'value' => 80]);

        $propertyId = $this->property($tenant['account']->id, 'VENDA');
        $email      = 'duplicado_' . bin2hex(random_bytes(4)) . '@teste.local';

        $service = new LeadService();
        $service->trySaveLead([
            'property_id'     => $propertyId,
            'nome_visitante'  => 'Visitante',
            'email_visitante' => $email,
            'mensagem'        => 'Primeira mensagem',
        ]);
        $service->trySaveLead([
            'property_id'     => $propertyId,
            'nome_visitante'  => 'Visitante',
            'email_visitante' => $email,
            'mensagem'        => 'Segunda mensagem, mesmo lead',
        ]);

        $this->assertSame(1, model(LeadChargeModel::class)->countAllResults());
    }
}
