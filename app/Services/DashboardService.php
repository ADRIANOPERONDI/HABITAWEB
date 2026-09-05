<?php

namespace App\Services;

use App\Entities\PlanFeature;
use App\Libraries\Metrics\DateRange;
use App\Models\AccountModel;
use App\Models\LeadModel;
use App\Models\PlanModel;
use App\Models\PropertyModel;
use App\Models\SubscriptionModel;
use CodeIgniter\Config\Factories;

class DashboardService
{
    protected AccountModel $accountModel;
    protected LeadModel $leadModel;
    protected PlanModel $planModel;
    protected PropertyModel $propertyModel;
    protected SubscriptionModel $subscriptionModel;

    public function __construct()
    {
        $this->accountModel      = Factories::models(AccountModel::class);
        $this->leadModel         = Factories::models(LeadModel::class);
        $this->planModel         = Factories::models(PlanModel::class);
        $this->propertyModel     = Factories::models(PropertyModel::class);
        $this->subscriptionModel = Factories::models(SubscriptionModel::class);
    }

    /**
     * Coleta todos os dados necessários para o dashboard.
     *
     * Cacheado por 60s por combinação conta/corretor/filtros: são ~15 queries
     * por render (métricas, gráficos, comparativos) — staleness de 1 minuto é
     * imperceptível num painel de métricas, e elimina o custo em recarregações
     * e navegação de ida-e-volta.
     */
    public function getDashboardData(int $accountId, array $filters = [], ?int $brokerId = null, bool $isSuperAdmin = false): array
    {
        $cacheKey = sprintf(
            'dashboard_%d_%s_%d_%s',
            $accountId,
            $brokerId ?? 'all',
            (int) $isSuperAdmin,
            md5(json_encode($filters))
        );

        $cached = cache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->buildDashboardData($accountId, $filters, $brokerId, $isSuperAdmin);
        cache()->save($cacheKey, $data, 60);

        return $data;
    }

    private function buildDashboardData(int $accountId, array $filters, ?int $brokerId, bool $isSuperAdmin): array
    {
        // 1. Dados da Conta e Plano
        $account = $this->accountModel->find($accountId);
        $subscription = $this->subscriptionModel->where('account_id', $accountId)->where('status', 'ACTIVE')->first();
        $plan = $subscription ? $this->planModel->find($subscription->plan_id) : null;

        $planName = $plan ? $plan->nome : 'Sem Plano';
        $limit    = $plan ? $plan->limite_imoveis_ativos : 0;
        $isUnlimited = ($limit === null);

        // 2. Alerta de Assinatura
        $subscriptionAlert = $this->getSubscriptionAlert($subscription);

        // 3. Filtros Disponíveis
        $neighborhoods = $this->propertyModel->getDistinctBairros($accountId);
        $condos        = $this->propertyModel->getDistinctCondominios($accountId);

        // 4. Métricas Principais
        $stats = [
            'imoveis_ativos' => $this->propertyModel->countActiveWithFilters($accountId, $filters, $brokerId),
            'leads_hoje'     => $this->leadModel->countTodayWithFilters($accountId, $filters, $brokerId),
            'visitas_total'  => $this->propertyModel->sumVisitsWithFilters($accountId, $filters, $brokerId),
            'plano'          => $planName,
            'limit'          => $isUnlimited ? 'Ilimitado' : $limit,
            'is_global'      => false
        ];

        // 5. Métricas Globais (SuperAdmin)
        if ($isSuperAdmin) {
            $stats['total_imoveis_global'] = $this->propertyModel->countAllResults();
            $stats['total_contas_global']  = $this->accountModel->countAllResults();
            $stats['total_leads_global']   = $this->leadModel->countAllResults();
            $stats['is_global'] = true;
        }

        // 6. Imóveis Recentes e Oportunidades
        $recentProperties = $this->propertyModel->getRecentWithFilters($accountId, 5, $filters, $brokerId);
        $opportunities    = $this->propertyModel->getOpportunities($accountId, $filters, $brokerId);

        // 7. Gráfico de Leads (últimos 7 dias) — GROUP BY no banco (Fase 4),
        // não mais um loop em PHP comparando data lead a lead.
        $range = DateRange::lastDays(7);
        $leadsPorDia = $this->leadModel->countsByDayWithFilters($accountId, $range->de, $range->ate, $filters, $brokerId);
        $chartData = ['labels' => [], 'values' => []];
        foreach ($range->dates() as $dia) {
            $chartData['labels'][] = date('d/m', strtotime($dia));
            $chartData['values'][] = $leadsPorDia[$dia] ?? 0;
        }

        // 8. Taxas e Comparativos
        $avgPriceUser = $this->propertyModel->getAvgPrice($accountId, $filters, $brokerId);
        $avgPriceMarket = $this->getMarketAvgPriceCached($filters);

        $cntLeadsTotal  = $this->leadModel->countTotalWithFilters($accountId, $filters, $brokerId);
        $conversionRate = ($stats['visitas_total'] > 0) ? ($cntLeadsTotal / $stats['visitas_total']) * 100 : 0;

        $stats['conversion_rate'] = number_format($conversionRate, 1, ',', '.');
        $stats['avg_ticket'] = number_format($avgPriceUser, 2, ',', '.');
        $stats['market_avg_ticket'] = number_format($avgPriceMarket, 2, ',', '.');
        $stats['ticket_status'] = ($avgPriceUser > $avgPriceMarket) ? 'above' : 'below';
        // Percentual real do ticket do usuário sobre a média de mercado, para
        // a barra de progresso — antes era um `width: 60%` fixo no HTML,
        // sempre 60% pra qualquer conta, qualquer dado.
        $stats['ticket_pct'] = $avgPriceMarket > 0 ? min(100, (int) round($avgPriceUser / $avgPriceMarket * 100)) : 0;

        // 9. Painel completo (Ouro/Diamante): PlanGate decide o que a view
        // exibe, não a view sozinha — rota AJAX avançada também precisa
        // desta checagem, não só a ocultação visual. Superadmin não é tenant
        // de plano nenhum e sempre viu o painel inteiro; PlanGate de uma
        // conta sem assinatura (o fallback account_id=1) devolveria false e
        // esconderia o próprio bloco global do superadmin.
        $painelCompleto      = $isSuperAdmin || PlanGate::has($accountId, PlanFeature::PAINEL_COMPLETO);
        $comparativoMercado  = $isSuperAdmin || PlanGate::has($accountId, PlanFeature::COMPARATIVO_MERCADO);

        $viewsComparado  = null;
        $leadsComparado  = null;
        $viewOrigins     = [];
        $marketShare     = null;

        if ($painelCompleto) {
            $metricsQuery   = new MetricsQueryService();
            $viewsComparado = $metricsQuery->viewsComparedForAccount($accountId, $range);
            $leadsComparado = $metricsQuery->leadsComparedForAccount($accountId, $range);
            $viewOrigins    = $metricsQuery->viewOriginsForAccount($accountId, $range);

            if ($comparativoMercado) {
                $cidade = $this->propertyModel->mostCommonCidade($accountId);

                if ($cidade !== null) {
                    $marketShare = $metricsQuery->marketShare($accountId, $cidade, $range);
                }
            }
        }

        return [
            'stats'              => $stats,
            'recentProperties'   => $recentProperties,
            'chartData'          => $chartData,
            'opportunities'      => $opportunities,
            'subscriptionAlert'  => $subscriptionAlert,
            'userDisplayName'    => $account ? $account->nome : 'Usuário',
            'neighborhoods'      => $neighborhoods,
            'condos'             => $condos,
            'painelCompleto'     => $painelCompleto,
            'comparativoMercado' => $comparativoMercado,
            'viewsComparado'     => $viewsComparado,
            'leadsComparado'     => $leadsComparado,
            'viewOrigins'        => $viewOrigins,
            'marketShare'        => $marketShare,
        ];
    }

    protected function getSubscriptionAlert($subscription): ?array
    {
        if (!$subscription || !$subscription->data_fim || app_setting('notify.subscription_expiry', '1') != '1') {
            return null;
        }

        $daysLeft = (strtotime($subscription->data_fim) - time()) / 86400;
        if ($daysLeft > 0 && $daysLeft <= 7) {
            return [
                'type' => 'warning',
                'message' => 'Sua assinatura vence em ' . ceil($daysLeft) . ' dia(s). Renove para evitar interrupções.'
            ];
        } elseif ($daysLeft <= 0) {
            return [
                'type' => 'danger',
                'message' => 'Sua assinatura está expirada. Alguns recursos podem estar limitados.'
            ];
        }
        return null;
    }

    protected function getMarketAvgPriceCached(array $filters): float
    {
        $cacheKey = 'avg_market_price_' . md5(serialize($filters));
        if (!$avgPriceMarket = cache($cacheKey)) {
            $avgPriceMarket = $this->propertyModel->getMarketAvgPrice($filters);
            cache()->save($cacheKey, $avgPriceMarket, 3600);
        }
        return (float) $avgPriceMarket;
    }
}
