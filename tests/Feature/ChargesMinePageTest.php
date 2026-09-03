<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\LeadChargeModel;
use App\Models\LeadChargeRuleModel;
use App\Models\LeadModel;
use App\Models\PropertyModel;
use App\Services\LeadChargeService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * `GET admin/minhas-cobrancas` (D3) — extrato do tenant. Cobre o filtro de
 * período (a tela sempre mostrou só o mês corrente, então uma cobrança de
 * meses atrás ficava invisível) e o tile "a pagar líquido do crédito", que
 * antes não existia — só "projetado" e "crédito", sem juntar os dois.
 */
final class ChargesMinePageTest extends HabitawebTestCase
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

    private function property(int $accountId): int
    {
        return (int) model(PropertyModel::class)->insert([
            'account_id'   => $accountId,
            'titulo'       => 'Imovel Cobranca',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 500000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);
    }

    private function lead(int $accountId, int $propertyId): object
    {
        $id = model(LeadModel::class)->insert([
            'property_id'           => $propertyId,
            'account_id_anunciante' => $accountId,
            'nome_visitante'        => 'Visitante',
            'email_visitante'       => 'visitante_' . bin2hex(random_bytes(4)) . '@teste.local',
            'telefone_visitante'    => '48999998888',
            'tipo_lead'             => 'MSG',
            'origem'                => 'SITE',
            'status'                => LeadModel::STATUS_NOVO,
            'tipo_negocio'          => 'VENDA',
        ], true);

        return model(LeadModel::class)->find($id);
    }

    public function testFiltraPorPeriodo(): void
    {
        $tenant     = (new TenantFactory())->create();
        $propertyId = $this->property($tenant['account']->id);

        // Cobrança do mes corrente, via o caminho real.
        $leadAtual = $this->lead($tenant['account']->id, $propertyId);
        (new LeadChargeService())->onLeadReceived($leadAtual);

        // Cobrança de um mes anterior — direto no model, ja que
        // onLeadReceived() sempre carimba o periodo de hoje.
        $leadAntigo    = $this->lead($tenant['account']->id, $propertyId);
        $periodoAntigo = date('Y-m-01', strtotime('-2 months'));
        model(LeadChargeModel::class)->insert([
            'account_id'        => $tenant['account']->id,
            'lead_id'           => $leadAntigo->id,
            'property_id'       => $propertyId,
            'tipo_negocio'      => 'VENDA',
            'origem'            => 'LEAD_RECEBIDO',
            'periodo'           => $periodoAntigo,
            'base_value'        => 0,
            'commission_value'  => 80,
            'status'            => LeadChargeModel::STATUS_APPROVED,
        ]);

        $mesCorrente = $this->actingAs($tenant['user'])->get('admin/minhas-cobrancas');
        $mesCorrente->assertStatus(200);
        $mesCorrente->assertSee('#' . $leadAtual->id);
        $mesCorrente->assertDontSee('#' . $leadAntigo->id);

        $mesAntigo = $this->actingAs($tenant['user'])->get('admin/minhas-cobrancas?periodo=' . substr($periodoAntigo, 0, 7));
        $mesAntigo->assertStatus(200);
        $mesAntigo->assertSee('#' . $leadAntigo->id);
        $mesAntigo->assertDontSee('#' . $leadAtual->id);
    }

    public function testMostraAPagarLiquidoDoCredito(): void
    {
        $tenant     = (new TenantFactory())->create();
        $propertyId = $this->property($tenant['account']->id);

        $lead = $this->lead($tenant['account']->id, $propertyId);
        (new LeadChargeService())->onLeadReceived($lead);
        model(LeadChargeModel::class)
            ->where('lead_id', $lead->id)
            ->set(['status' => LeadChargeModel::STATUS_APPROVED])
            ->update();

        $response = $this->actingAs($tenant['user'])->get('admin/minhas-cobrancas');

        $response->assertStatus(200);
        // Sem crédito nenhum concedido: projetado 80, credito 0, a pagar = 80.
        $response->assertSee('A pagar (líquido do crédito)');
        $response->assertSee('80,00');
    }
}
