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
        'total_fetched', 'created_count', 'updated_count', 'skipped_count',
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
}
