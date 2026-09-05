<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Histórico de execuções de sincronização.
 */
class IntegrationSyncRunModel extends Model
{
    protected $table            = 'integration_sync_runs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\IntegrationSyncRun::class;
    protected $allowedFields    = [
        'account_integration_id', 'trigger_type', 'status', 'started_at', 'finished_at',
        'total_fetched', 'created_count', 'updated_count', 'skipped_count', 'ignored_count',
        'paused_count', 'images_count', 'error_count', 'error_message',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_PARTIAL = 'PARTIAL';
    public const STATUS_ERROR   = 'ERROR';

    public const TRIGGER_CRON   = 'cron';
    public const TRIGGER_MANUAL = 'manual';

    public function start(int $accountIntegrationId, string $trigger): int
    {
        return (int) $this->insert([
            'account_integration_id' => $accountIntegrationId,
            'trigger_type'           => $trigger,
            'status'                 => self::STATUS_RUNNING,
            'started_at'             => date('Y-m-d H:i:s'),
        ], true);
    }

    public function finish(int $runId, string $status, array $counters = [], ?string $errorMessage = null): bool
    {
        return (bool) $this->update($runId, array_merge($counters, [
            'status'        => $status,
            'finished_at'   => date('Y-m-d H:i:s'),
            'error_message' => $errorMessage === null ? null : mb_substr($errorMessage, 0, 2000),
        ]));
    }

    /** @return \App\Entities\IntegrationSyncRun[] */
    public function recentFor(int $accountIntegrationId, int $limit = 20): array
    {
        return $this->where('account_integration_id', $accountIntegrationId)
            ->orderBy('started_at', 'DESC')
            ->findAll($limit);
    }

    public function lastFor(int $accountIntegrationId): ?\App\Entities\IntegrationSyncRun
    {
        return $this->where('account_integration_id', $accountIntegrationId)
            ->orderBy('started_at', 'DESC')
            ->first();
    }

    /**
     * Fecha rodadas que ficaram RUNNING além do razoável — um Fatal Error de
     * PHP (max_execution_time, por exemplo) mata o processo antes de
     * qualquer finally rodar, e a linha nunca é fechada sozinha. Sem isto, a
     * tela de execuções mostra "Rodando" com duração "—" para sempre, e não
     * há como saber se a integração está travada ou só lenta.
     *
     * @return int quantas rodadas foram fechadas
     */
    public function closeStaleRunning(int $maxAgeSeconds): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $maxAgeSeconds);

        $stale = $this->where('status', self::STATUS_RUNNING)
            ->where('started_at <', $cutoff)
            ->findAll();

        foreach ($stale as $run) {
            $this->finish(
                (int) $run->id,
                self::STATUS_ERROR,
                [],
                'Rodada interrompida — processo encerrado sem finalizar (Fatal Error ou reinício do servidor).'
            );
        }

        return count($stale);
    }

    /**
     * Erros seguidos (as ÚLTIMAS rodadas, sem nenhum sucesso no meio) — o
     * sinal usado pra decidir se um erro de transporte pontual (Simob fora
     * do ar por um instante) já virou algo estrutural que justifica desligar
     * o sync (ver IntegrationSyncService::run()).
     */
    public function consecutiveErrors(int $accountIntegrationId, int $limit = 5): int
    {
        $runs = $this->where('account_integration_id', $accountIntegrationId)
            ->orderBy('started_at', 'DESC')
            ->findAll($limit);

        $count = 0;

        foreach ($runs as $run) {
            if ($run->status !== self::STATUS_ERROR) {
                break;
            }

            $count++;
        }

        return $count;
    }
}
