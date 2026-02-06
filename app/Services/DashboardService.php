<?php

namespace App\Services;

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
     */
    public function getDashboardData(int $accountId, array $filters = [], ?int $brokerId = null, bool $isSuperAdmin = false): array
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

        // 7. Gráfico de Leads (Últimos 7 dias)
        $chartData = $this->formatChartData($this->leadModel->getLeadsLast7Days($accountId, $filters, $brokerId));

        // 8. Taxas e Comparativos
        $avgPriceUser = $this->propertyModel->getAvgPrice($accountId, $filters, $brokerId);
        $avgPriceMarket = $this->getMarketAvgPriceCached($filters);
        
        $cntLeadsTotal  = $this->leadModel->countTotalWithFilters($accountId, $filters, $brokerId);
        $conversionRate = ($stats['visitas_total'] > 0) ? ($cntLeadsTotal / $stats['visitas_total']) * 100 : 0;

        $stats['conversion_rate'] = number_format($conversionRate, 1, ',', '.');
        $stats['avg_ticket'] = number_format($avgPriceUser, 2, ',', '.');
        $stats['market_avg_ticket'] = number_format($avgPriceMarket, 2, ',', '.');
        $stats['ticket_status'] = ($avgPriceUser > $avgPriceMarket) ? 'above' : 'below';

        return [
            'stats'            => $stats,
            'recentProperties' => $recentProperties,
            'chartData'        => $chartData,
            'opportunities'    => $opportunities,
            'subscriptionAlert' => $subscriptionAlert,
            'userDisplayName'  => $account ? $account->nome : 'Usuário',
            'neighborhoods'    => $neighborhoods,
            'condos'           => $condos,
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

    protected function formatChartData(array $leads): array
    {
        $chartData = ['labels' => [], 'values' => []];
        $interval = new \DateInterval('P1D');
        $period = new \DatePeriod(new \DateTime('-6 days'), $interval, new \DateTime('+1 day'));
        
        foreach ($period as $dt) {
            $dateStr = $dt->format('Y-m-d');
            $count = 0;
            foreach ($leads as $lead) {
                $leadDateRaw = is_object($lead->created_at) && method_exists($lead->created_at, 'format') 
                    ? $lead->created_at->format('Y-m-d') 
                    : substr((string)$lead->created_at, 0, 10);
                
                if ($leadDateRaw === $dateStr) $count++;
            }
            $chartData['labels'][] = $dt->format('d/m');
            $chartData['values'][] = $count;
        }
        return $chartData;
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
