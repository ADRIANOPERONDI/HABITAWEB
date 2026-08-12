<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class IntegrationSyncRun extends Entity
{
    protected $casts = [
        'id'                     => 'integer',
        'account_integration_id' => 'integer',
        'total_fetched'          => 'integer',
        'created_count'          => 'integer',
        'updated_count'          => 'integer',
        'skipped_count'          => 'integer',
        'paused_count'           => 'integer',
        'images_count'           => 'integer',
        'error_count'            => 'integer',
    ];

    protected $dates = ['started_at', 'finished_at', 'created_at', 'updated_at'];

    public function durationSeconds(): ?int
    {
        if (empty($this->attributes['started_at']) || empty($this->attributes['finished_at'])) {
            return null;
        }

        return strtotime($this->attributes['finished_at']) - strtotime($this->attributes['started_at']);
    }

    /** Classe de badge Bootstrap para a tela de execuções. */
    public function statusBadge(): string
    {
        return match ($this->attributes['status'] ?? '') {
            \App\Models\IntegrationSyncRunModel::STATUS_SUCCESS => 'success',
            \App\Models\IntegrationSyncRunModel::STATUS_PARTIAL => 'warning',
            \App\Models\IntegrationSyncRunModel::STATUS_ERROR   => 'danger',
            default                                             => 'secondary',
        };
    }
}
