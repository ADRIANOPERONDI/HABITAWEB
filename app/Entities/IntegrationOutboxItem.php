<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;
use App\Models\IntegrationOutboxModel;

class IntegrationOutboxItem extends Entity
{
    protected $casts = [
        'id'                     => 'integer',
        'account_integration_id' => 'integer',
        'reference_id'           => '?integer',
        'attempts'               => 'integer',
    ];

    protected $dates = ['next_attempt_at', 'sent_at', 'created_at', 'updated_at'];

    public function payloadArray(): array
    {
        $payload = $this->attributes['payload'] ?? null;

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        return is_array($payload) ? $payload : [];
    }

    public function isSent(): bool
    {
        return ($this->attributes['status'] ?? null) === IntegrationOutboxModel::STATUS_SENT;
    }

    public function isFailed(): bool
    {
        return ($this->attributes['status'] ?? null) === IntegrationOutboxModel::STATUS_FAILED;
    }

    /** Rótulo curto para o selo na tela de leads. */
    public function label(): string
    {
        return match ($this->attributes['status'] ?? '') {
            IntegrationOutboxModel::STATUS_SENT   => 'Enviado ao CRM',
            IntegrationOutboxModel::STATUS_FAILED => 'Falha no envio ao CRM',
            default                               => 'Envio ao CRM pendente',
        };
    }

    public function badge(): string
    {
        return match ($this->attributes['status'] ?? '') {
            IntegrationOutboxModel::STATUS_SENT   => 'success',
            IntegrationOutboxModel::STATUS_FAILED => 'danger',
            default                               => 'secondary',
        };
    }
}
