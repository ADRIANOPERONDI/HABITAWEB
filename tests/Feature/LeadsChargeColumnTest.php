<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\LeadChargeRuleModel;
use App\Models\LeadModel;
use App\Models\PropertyModel;
use App\Services\LeadChargeService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Coluna "Cobrança" da lista de leads (D3) — batch lookup por
 * `LeadChargeModel::findByLeadIds()`, mesmo padrão de `crmStatusFor()` já
 * usado ali pro status de envio ao CRM.
 */
final class LeadsChargeColumnTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        cache()->clean();

        model(LeadChargeRuleModel::class)->insert([
            'account_id'    => null,
            'provider_code' => null,
            'tipo_negocio'  => 'VENDA',
            'model'         => LeadChargeRuleModel::MODEL_FIXED,
            'value'         => 80,
            'is_active'     => true,
        ]);
    }

    public function testListaMostraValorEStatusDaCobrancaDoLead(): void
    {
        $tenant = (new TenantFactory())->create();

        $propertyId = (int) model(PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Imovel Lista',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 500000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);

        $leadId = model(LeadModel::class)->insert([
            'property_id'           => $propertyId,
            'account_id_anunciante' => $tenant['account']->id,
            'nome_visitante'        => 'Visitante Lista',
            'email_visitante'       => 'visitante_' . bin2hex(random_bytes(4)) . '@teste.local',
            'telefone_visitante'    => '48999998888',
            'tipo_lead'             => 'MSG',
            'origem'                => 'SITE',
            'status'                => LeadModel::STATUS_NOVO,
            'tipo_negocio'          => 'VENDA',
        ], true);

        (new LeadChargeService())->onLeadReceived(model(LeadModel::class)->find($leadId));

        $response = $this->actingAs($tenant['user'])->get('admin/leads');

        $response->assertStatus(200);
        $response->assertSee('Cobrança');
        $response->assertSee('Aguardando aprovação');
        $response->assertSee('80,00');
    }
}
