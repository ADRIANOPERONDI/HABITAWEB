<?php

namespace App\Services;

use App\Libraries\Metrics\DateRange;
use App\Models\LeadModel;
use App\Models\PropertyModel;
use App\Models\PropertyViewDailyModel;
use App\Models\PropertyViewSourceDailyModel;
use App\Models\SearchDailyModel;
use CodeIgniter\Config\Factories;

/**
 * Consulta às séries diárias (Fase 4) — o lado leitura de
 * `RedisMetricsBuffer`/`metrics:flush`. Todo total é `GROUP BY dia` no
 * banco; nada varre linha por linha em PHP (era o problema de
 * `DashboardService::formatChartData`, que este service substitui).
 */
class MetricsQueryService
{
    public function __construct(
        private ?PropertyViewDailyModel $viewDaily = null,
        private ?PropertyViewSourceDailyModel $viewSourceDaily = null,
        private ?SearchDailyModel $searchDaily = null,
        private ?LeadModel $leadModel = null,
        private ?PropertyModel $propertyModel = null,
    ) {
        $this->viewDaily       ??= Factories::models(PropertyViewDailyModel::class);
        $this->viewSourceDaily ??= Factories::models(PropertyViewSourceDailyModel::class);
        $this->searchDaily     ??= Factories::models(SearchDailyModel::class);
        $this->leadModel       ??= Factories::models(LeadModel::class);
        $this->propertyModel   ??= Factories::models(PropertyModel::class);
    }

    // -------------------------------------------------------- visualizações

    public function viewsTotalForAccount(int $accountId, DateRange $range): int
    {
        return $this->viewDaily->totalForAccount($accountId, $range->de, $range->ate);
    }

    /** Série dia-a-dia com zero preenchido nos dias sem dado — pronta para o gráfico. */
    public function viewsSeriesForAccount(int $accountId, DateRange $range): array
    {
        $raw = $this->viewDaily->dailySeriesForAccount($accountId, $range->de, $range->ate);
        $out = [];

        foreach ($range->dates() as $dia) {
            $out[$dia] = $raw[$dia] ?? 0;
        }

        return $out;
    }

    public function viewOriginsForAccount(int $accountId, DateRange $range): array
    {
        return $this->viewSourceDaily->originsForAccount($accountId, $range->de, $range->ate);
    }

    // -------------------------------------------------------------- leads

    public function leadsTotalForAccount(int $accountId, DateRange $range): int
    {
        return (int) $this->leadModel
            ->where('account_id_anunciante', $accountId)
            ->where('created_at >=', $range->de . ' 00:00:00')
            ->where('created_at <=', $range->ate . ' 23:59:59')
            ->countAllResults();
    }

    /**
     * Série dia-a-dia de leads — GROUP BY no banco. Substitui o loop de
     * `DashboardService::formatChartData`, que varria todos os leads do
     * período em PHP comparando string de data um a um.
     */
    public function leadsSeriesForAccount(int $accountId, DateRange $range): array
    {
        $rows = $this->leadModel
            ->select("DATE(created_at) as dia, COUNT(*) as total")
            ->where('account_id_anunciante', $accountId)
            ->where('created_at >=', $range->de . ' 00:00:00')
            ->where('created_at <=', $range->ate . ' 23:59:59')
            ->groupBy('DATE(created_at)')
            ->findAll();

        $porDia = [];
        foreach ($rows as $row) {
            $dia = $row->dia instanceof \DateTimeInterface ? $row->dia->format('Y-m-d') : (string) $row->dia;
            $porDia[$dia] = (int) $row->total;
        }

        $out = [];
        foreach ($range->dates() as $dia) {
            $out[$dia] = $porDia[$dia] ?? 0;
        }

        return $out;
    }

    // ------------------------------------------------------- comparação

    /**
     * Totais do período atual vs. o período anterior de mesma duração — a
     * base das setas ↑↓ do painel completo.
     *
     * @return array{atual: int, anterior: int, variacao_pct: ?float}
     */
    public function compare(int $atual, int $anterior): array
    {
        $variacao = $anterior > 0
            ? round((($atual - $anterior) / $anterior) * 100, 1)
            : ($atual > 0 ? 100.0 : null);

        return ['atual' => $atual, 'anterior' => $anterior, 'variacao_pct' => $variacao];
    }

    public function viewsComparedForAccount(int $accountId, DateRange $range): array
    {
        return $this->compare(
            $this->viewsTotalForAccount($accountId, $range),
            $this->viewsTotalForAccount($accountId, $range->previous())
        );
    }

    public function leadsComparedForAccount(int $accountId, DateRange $range): array
    {
        return $this->compare(
            $this->leadsTotalForAccount($accountId, $range),
            $this->leadsTotalForAccount($accountId, $range->previous())
        );
    }

    // --------------------------------------------------- inteligência de mercado

    /** Bairros mais buscados de uma cidade no período. */
    public function marketNeighborhoods(string $cidade, DateRange $range, int $limit = 10): array
    {
        return $this->searchDaily->topBairros($cidade, $range->de, $range->ate, $limit);
    }

    /**
     * Participação da conta na oferta (imóveis ativos) e nos leads da
     * cidade, no período — o "comparativo de mercado" do plano Diamante.
     *
     * @return array{leads_conta: int, leads_cidade: int, leads_share_pct: float, imoveis_conta: int, imoveis_cidade: int, oferta_share_pct: float}
     */
    public function marketShare(int $accountId, string $cidade, DateRange $range): array
    {
        $leadsConta = $this->leadsTotalForAccount($accountId, $range);

        $leadsCidade = (int) $this->leadModel
            ->select('leads.id')
            ->join('properties', 'properties.id = leads.property_id')
            ->where('properties.cidade', $cidade)
            ->where('leads.created_at >=', $range->de . ' 00:00:00')
            ->where('leads.created_at <=', $range->ate . ' 23:59:59')
            ->countAllResults();

        $imoveisConta = (int) $this->propertyModel
            ->where('account_id', $accountId)
            ->where('cidade', $cidade)
            ->where('status', 'ACTIVE')
            ->countAllResults();

        $imoveisCidade = (int) $this->propertyModel
            ->where('cidade', $cidade)
            ->where('status', 'ACTIVE')
            ->countAllResults();

        return [
            'leads_conta'      => $leadsConta,
            'leads_cidade'     => $leadsCidade,
            'leads_share_pct'  => $leadsCidade > 0 ? round($leadsConta / $leadsCidade * 100, 1) : 0.0,
            'imoveis_conta'    => $imoveisConta,
            'imoveis_cidade'   => $imoveisCidade,
            'oferta_share_pct' => $imoveisCidade > 0 ? round($imoveisConta / $imoveisCidade * 100, 1) : 0.0,
        ];
    }
}
