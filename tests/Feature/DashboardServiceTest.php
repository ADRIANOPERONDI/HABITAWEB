<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\LeadModel;
use App\Models\PropertyModel;
use App\Models\PropertyViewDailyModel;
use App\Services\DashboardService;
use App\Services\PlanGate;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * `DashboardService` ramificado por `PlanGate::has(PAINEL_COMPLETO)` — Prata
 * vê só o básico, Ouro ganha o painel completo, Diamante ganha também o
 * comparativo de mercado. Superadmin sempre vê tudo, independente de plano.
 */
final class DashboardServiceTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        PlanGate::flushMemo();
        cache()->clean();
    }

    private function property(int $accountId, string $cidade = 'Chapecó'): int
    {
        return (int) model(PropertyModel::class)->insert([
            'account_id'   => $accountId,
            'titulo'       => 'Imovel Dashboard',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 400000,
            'cidade'       => $cidade,
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);
    }

    public function testPrataNaoTemPainelCompleto(): void
    {
        $tenant = (new TenantFactory())->create([], 'PRATA');

        $data = (new DashboardService())->getDashboardData((int) $tenant['account']->id);

        $this->assertFalse($data['painelCompleto']);
        $this->assertFalse($data['comparativoMercado']);
        $this->assertNull($data['viewsComparado']);
        $this->assertNull($data['leadsComparado']);
        $this->assertNull($data['marketShare']);
        $this->assertSame([], $data['viewOrigins']);
    }

    public function testOuroTemPainelCompletoMasNaoComparativoDeMercado(): void
    {
        $tenant = (new TenantFactory())->create([], 'OURO');

        $data = (new DashboardService())->getDashboardData((int) $tenant['account']->id);

        $this->assertTrue($data['painelCompleto']);
        $this->assertFalse($data['comparativoMercado']);
        $this->assertNotNull($data['viewsComparado']);
        $this->assertNotNull($data['leadsComparado']);
        $this->assertNull($data['marketShare']);
    }

    public function testDiamanteTemComparativoDeMercado(): void
    {
        $tenant     = (new TenantFactory())->create([], 'DIAMANTE');
        $propertyId = $this->property((int) $tenant['account']->id);

        $data = (new DashboardService())->getDashboardData((int) $tenant['account']->id);

        $this->assertTrue($data['painelCompleto']);
        $this->assertTrue($data['comparativoMercado']);
        $this->assertNotNull($data['marketShare']);
        $this->assertSame(1, $data['marketShare']['imoveis_conta']);
        $this->assertSame(100.0, $data['marketShare']['oferta_share_pct']);
    }

    public function testSuperAdminSempreVePainelCompletoIndependenteDePlano(): void
    {
        $tenant = (new TenantFactory())->create([], 'PRATA');

        $data = (new DashboardService())->getDashboardData((int) $tenant['account']->id, [], null, isSuperAdmin: true);

        $this->assertTrue($data['painelCompleto']);
        $this->assertTrue($data['comparativoMercado']);
        $this->assertTrue($data['stats']['is_global']);
    }

    /**
     * `getMarketAvgPrice` é global (todas as ativas da plataforma), então o
     * teste não pode prever um número exato sem conhecer todo o fixture
     * compartilhado — mas pode provar as duas garantias que a correção
     * introduziu sobre o `width: 60%` fixo antigo: o percentual reflete o
     * preço de verdade (sem outro imóvel algum, sem conta com preço nenhum,
     * a média de mercado é 0 e o resultado tem que ser 0, não 60 nem uma
     * divisão por zero), e nunca estoura os limites 0–100.
     */
    public function testTicketPctReflete0QuandoNaoHaMediaDeMercadoENuncaEstouraOsLimites(): void
    {
        $tenant = (new TenantFactory())->create([], 'PRATA');

        $semImovel = (new DashboardService())->getDashboardData((int) $tenant['account']->id);
        $this->assertSame(0, $semImovel['stats']['ticket_pct'], 'sem imovel nenhum, media de mercado e 0 — nao pode dividir por zero nem sobrar o 60 fixo antigo');

        model(PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Imovel Caro',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 999999999,
            'cidade'       => 'Ticket City ' . bin2hex(random_bytes(3)),
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ]);
        cache()->clean();

        $comImovelCaro = (new DashboardService())->getDashboardData((int) $tenant['account']->id);
        $this->assertGreaterThanOrEqual(0, $comImovelCaro['stats']['ticket_pct']);
        $this->assertLessThanOrEqual(100, $comImovelCaro['stats']['ticket_pct']);
    }

    public function testChartDataTemSeteDiasRefletindoLeadsReais(): void
    {
        $tenant     = (new TenantFactory())->create([], 'PRATA');
        $propertyId = $this->property((int) $tenant['account']->id);

        model(LeadModel::class)->insert([
            'property_id' => $propertyId, 'account_id_anunciante' => $tenant['account']->id,
            'nome_visitante' => 'V', 'email_visitante' => 'v_' . bin2hex(random_bytes(3)) . '@teste.local',
            'tipo_lead' => 'MSG', 'origem' => 'SITE', 'status' => 'NOVO',
        ]);

        $data = (new DashboardService())->getDashboardData((int) $tenant['account']->id);

        $this->assertCount(7, $data['chartData']['labels']);
        $this->assertCount(7, $data['chartData']['values']);
        $this->assertSame(1, array_sum($data['chartData']['values']));
    }

    public function testViewsComparadoRefleteSerieDiaria(): void
    {
        $tenant     = (new TenantFactory())->create([], 'OURO');
        $propertyId = $this->property((int) $tenant['account']->id);

        model(PropertyViewDailyModel::class)->upsertCounters($propertyId, date('Y-m-d'), 7, 4);

        $data = (new DashboardService())->getDashboardData((int) $tenant['account']->id);

        $this->assertSame(7, $data['viewsComparado']['atual']);
    }

    // ------------------------------------------------------- render real

    public function testPrataRendeSemAGrandeQueTraCartaoDeUpsell(): void
    {
        $tenant = (new TenantFactory())->create([], 'PRATA');

        $html = $this->actingAs($tenant['user'])->get('admin/dashboard')->getBody();

        $this->assertStringContainsString('Desbloqueie o painel completo', $html);
        $this->assertStringNotContainsString('leadsChart', $html);
    }

    public function testDiamanteRendeOPainelCompletoComOGraficoEComparativo(): void
    {
        $tenant = (new TenantFactory())->create([], 'DIAMANTE');
        $this->property((int) $tenant['account']->id);

        $html = $this->actingAs($tenant['user'])->get('admin/dashboard')->getBody();

        $this->assertStringContainsString('leadsChart', $html);
        $this->assertStringContainsString('Comparativo de Mercado', $html);
        $this->assertStringNotContainsString('Desbloqueie o painel completo', $html);
    }
}
