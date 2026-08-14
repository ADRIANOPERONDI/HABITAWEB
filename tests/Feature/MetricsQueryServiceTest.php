<?php

namespace Tests\Feature;

use App\Libraries\Metrics\DateRange;
use App\Models\LeadModel;
use App\Models\PropertyModel;
use App\Models\PropertyViewDailyModel;
use App\Models\PropertyViewSourceDailyModel;
use App\Models\SearchDailyModel;
use App\Services\MetricsQueryService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

final class MetricsQueryServiceTest extends HabitawebTestCase
{
    // -------------------------------------------------------------- DateRange

    public function testLastDaysCobreOIntervaloCorreto(): void
    {
        $range = DateRange::lastDays(7);

        $this->assertSame(7, $range->days());
        $this->assertSame(date('Y-m-d'), $range->ate);
        $this->assertSame(date('Y-m-d', strtotime('-6 days')), $range->de);
    }

    public function testPreviousEImediatamenteAnteriorComMesmaDuracao(): void
    {
        $range    = new DateRange('2026-08-08', '2026-08-14'); // 7 dias
        $anterior = $range->previous();

        $this->assertSame('2026-08-01', $anterior->de);
        $this->assertSame('2026-08-07', $anterior->ate);
        $this->assertSame(7, $anterior->days());
    }

    public function testDatesListaTodosOsDiasDoIntervalo(): void
    {
        $range = new DateRange('2026-08-01', '2026-08-03');

        $this->assertSame(['2026-08-01', '2026-08-02', '2026-08-03'], $range->dates());
    }

    // ------------------------------------------------------- MetricsQueryService

    private function tenantComImovel(string $cidade = 'Chapecó'): array
    {
        $tenant     = (new TenantFactory())->create();
        $propertyId = (int) model(PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Imovel Query',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 400000,
            'cidade'       => $cidade,
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);

        return [$tenant, $propertyId];
    }

    public function testViewsSeriesForAccountPreencheZeroNosDiasSemDado(): void
    {
        [$tenant, $propertyId] = $this->tenantComImovel();
        $hoje = date('Y-m-d');

        model(PropertyViewDailyModel::class)->upsertCounters($propertyId, $hoje, 5, 3);

        $range  = new DateRange(date('Y-m-d', strtotime('-2 days')), $hoje);
        $series = (new MetricsQueryService())->viewsSeriesForAccount((int) $tenant['account']->id, $range);

        $this->assertCount(3, $series);
        $this->assertSame(5, $series[$hoje]);
        $this->assertSame(0, $series[date('Y-m-d', strtotime('-1 day'))]);
    }

    public function testViewsTotalForAccountSomaSoDoImovelDaConta(): void
    {
        [$tenantA, $propA] = $this->tenantComImovel();
        [$tenantB, $propB] = $this->tenantComImovel();
        $hoje = date('Y-m-d');

        model(PropertyViewDailyModel::class)->upsertCounters($propA, $hoje, 10, 5);
        model(PropertyViewDailyModel::class)->upsertCounters($propB, $hoje, 999, 999);

        $range = new DateRange($hoje, $hoje);
        $total = (new MetricsQueryService())->viewsTotalForAccount((int) $tenantA['account']->id, $range);

        $this->assertSame(10, $total);
    }

    public function testLeadsSeriesForAccountAgrupaPorDiaNoBanco(): void
    {
        [$tenant, $propertyId] = $this->tenantComImovel();
        $hoje = date('Y-m-d');

        $leadModel = model(LeadModel::class);
        foreach (range(1, 3) as $i) {
            $leadModel->insert([
                'property_id'           => $propertyId,
                'account_id_anunciante' => $tenant['account']->id,
                'nome_visitante'        => "Visitante {$i}",
                'email_visitante'       => "visitante{$i}_" . bin2hex(random_bytes(3)) . '@teste.local',
                'tipo_lead'             => 'MSG',
                'origem'                => 'SITE',
                'status'                => 'NOVO',
            ]);
        }

        $range  = new DateRange($hoje, $hoje);
        $series = (new MetricsQueryService())->leadsSeriesForAccount((int) $tenant['account']->id, $range);

        $this->assertSame(3, $series[$hoje]);
    }

    public function testCompareCalculaVariacaoPercentual(): void
    {
        $service = new MetricsQueryService();

        $this->assertSame(['atual' => 15, 'anterior' => 10, 'variacao_pct' => 50.0], $service->compare(15, 10));
        $this->assertSame(['atual' => 5, 'anterior' => 10, 'variacao_pct' => -50.0], $service->compare(5, 10));
        $this->assertSame(['atual' => 0, 'anterior' => 0, 'variacao_pct' => null], $service->compare(0, 0));
        $this->assertSame(['atual' => 3, 'anterior' => 0, 'variacao_pct' => 100.0], $service->compare(3, 0));
    }

    public function testMarketNeighborhoodsRetornaOsMaisBuscados(): void
    {
        $dia   = date('Y-m-d');
        $model = model(SearchDailyModel::class);
        $model->upsertCounter($dia, ['VENDA', 'Chapecó', 'Centro', '', 'QUALQUER'], 8);
        $model->upsertCounter($dia, ['VENDA', 'Chapecó', 'Efapi', '', 'QUALQUER'], 3);

        $range = new DateRange($dia, $dia);
        $top   = (new MetricsQueryService())->marketNeighborhoods('Chapecó', $range);

        $this->assertSame('Centro', $top[0]['bairro']);
        $this->assertSame(8, $top[0]['buscas']);
    }

    public function testMarketShareCalculaParticipacaoNaOfertaENosLeads(): void
    {
        $cidade = 'Metricas City ' . bin2hex(random_bytes(3));
        [$tenantA, $propA] = $this->tenantComImovel($cidade);
        [$tenantB, $propB] = $this->tenantComImovel($cidade);

        $leadModel = model(LeadModel::class);
        $leadModel->insert([
            'property_id' => $propA, 'account_id_anunciante' => $tenantA['account']->id,
            'nome_visitante' => 'V1', 'email_visitante' => 'v1_' . bin2hex(random_bytes(3)) . '@teste.local',
            'tipo_lead' => 'MSG', 'origem' => 'SITE', 'status' => 'NOVO',
        ]);
        $leadModel->insert([
            'property_id' => $propB, 'account_id_anunciante' => $tenantB['account']->id,
            'nome_visitante' => 'V2', 'email_visitante' => 'v2_' . bin2hex(random_bytes(3)) . '@teste.local',
            'tipo_lead' => 'MSG', 'origem' => 'SITE', 'status' => 'NOVO',
        ]);
        $leadModel->insert([
            'property_id' => $propB, 'account_id_anunciante' => $tenantB['account']->id,
            'nome_visitante' => 'V3', 'email_visitante' => 'v3_' . bin2hex(random_bytes(3)) . '@teste.local',
            'tipo_lead' => 'MSG', 'origem' => 'SITE', 'status' => 'NOVO',
        ]);

        $range = DateRange::lastDays(1);
        $share = (new MetricsQueryService())->marketShare((int) $tenantA['account']->id, $cidade, $range);

        $this->assertSame(1, $share['imoveis_conta']);
        $this->assertSame(2, $share['imoveis_cidade']);
        $this->assertSame(50.0, $share['oferta_share_pct']);
        $this->assertSame(1, $share['leads_conta']);
        $this->assertSame(3, $share['leads_cidade']);
        $this->assertEqualsWithDelta(33.3, $share['leads_share_pct'], 0.1);
    }
}
