<?php

namespace App\Commands;

use App\Models\PropertyViewDailyModel;
use App\Models\PropertyViewSourceDailyModel;
use App\Models\SearchDailyModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Descarrega no Postgres as métricas bufferizadas em Redis:
 * - Contadores de visita de imóvel (PropertyService::incrementVisit) — um
 *   UPDATE agregado por imóvel em vez de um UPDATE por page view.
 * - Recálculos de score adiados pelo debounce do RankingService.
 * - Séries diárias do painel por período (Fase 4): visualização por imóvel,
 *   por origem, e busca — todas via UPSERT que SOMA, não substitui.
 *
 * Agendar via cron (numa instância só, junto dos demais crons):
 *   *\/5 * * * * php spark metrics:flush
 *
 * Seguro contra perda: se o UPDATE falhar, a contagem volta pro Redis e é
 * reprocessada na próxima execução (ver RedisMetricsBuffer::flushVisits).
 */
class FlushMetrics extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'metrics:flush';
    protected $description = 'Descarrega visitas bufferizadas e scores de ranking pendentes do Redis para o banco.';

    public function run(array $params)
    {
        $buffer = service('metricsBuffer');
        $db     = \Config\Database::connect();

        // 1. Visitas
        $flushed = $buffer->flushVisits(static function (int $propertyId, int $count) use ($db): bool {
            $db->table('properties')
               ->where('id', $propertyId)
               ->set('visitas_count', "visitas_count + {$count}", false)
               ->update();

            return $db->affectedRows() >= 0; // update de id inexistente não é erro (imóvel deletado)
        });

        CLI::write("Visitas: {$flushed} imóvel(is) atualizados.", 'green');

        // 2. Ranking pendente (debounce do RankingService)
        $rankingService = service('rankingService');
        $recalculated = 0;

        foreach ($buffer->popRankingDirty() as $propertyId) {
            $rankingService->updateScore($propertyId, force: true);
            $recalculated++;
        }

        CLI::write("Ranking: {$recalculated} score(s) recalculado(s).", 'green');

        // 3. Série diária de visualização por imóvel
        $viewDailyModel = model(PropertyViewDailyModel::class);
        $viewsFlushed   = $buffer->flushPropertyViews(
            static fn (int $propertyId, string $dia, int $views, int $unicas): bool =>
                $viewDailyModel->upsertCounters($propertyId, $dia, $views, $unicas)
        );
        CLI::write("Views diárias: {$viewsFlushed} série(s) atualizada(s).", 'green');

        // 4. Série diária de visualização por origem
        $sourceDailyModel = model(PropertyViewSourceDailyModel::class);
        $sourcesFlushed   = $buffer->flushPropertyViewSources(
            static fn (int $propertyId, string $dia, string $origem, int $views): bool =>
                $sourceDailyModel->upsertCounter($propertyId, $dia, $origem, $views)
        );
        CLI::write("Views por origem: {$sourcesFlushed} série(s) atualizada(s).", 'green');

        // 5. Série diária de busca
        $searchDailyModel = model(SearchDailyModel::class);
        $searchesFlushed  = $buffer->flushSearches(
            static fn (string $dia, array $dims, int $buscas): bool =>
                $searchDailyModel->upsertCounter($dia, $dims, $buscas)
        );
        CLI::write("Buscas: {$searchesFlushed} série(s) atualizada(s).", 'green');
    }
}
