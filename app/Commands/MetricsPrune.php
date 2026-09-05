<?php

namespace App\Commands;

use App\Models\PropertyViewDailyModel;
use App\Models\PropertyViewSourceDailyModel;
use App\Models\SearchDailyModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Retenção de 24 meses para as séries diárias de métricas (Fase 4). Cron
 * mensal — a granularidade é diária, então o volume por dia é pequeno, mas
 * sem retenção as tabelas cresceriam para sempre.
 */
class MetricsPrune extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'metrics:prune';
    protected $description = 'Remove series diarias de metricas com mais de 24 meses.';

    public function run(array $params)
    {
        $meses  = (int) (CLI::getOption('meses') ?? 24);
        $limite = date('Y-m-d', strtotime("-{$meses} months"));

        CLI::write("Removendo series de metricas anteriores a {$limite}...", 'yellow');

        $views   = model(PropertyViewDailyModel::class)->pruneOlderThan($limite);
        $origens = model(PropertyViewSourceDailyModel::class)->pruneOlderThan($limite);
        $buscas  = model(SearchDailyModel::class)->pruneOlderThan($limite);

        CLI::write("property_view_daily: {$views} linha(s) removida(s).", 'green');
        CLI::write("property_view_source_daily: {$origens} linha(s) removida(s).", 'green');
        CLI::write("search_daily: {$buscas} linha(s) removida(s).", 'green');
    }
}
