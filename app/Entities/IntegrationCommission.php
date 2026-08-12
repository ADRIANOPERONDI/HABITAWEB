<?php

namespace App\Entities;

use App\Models\IntegrationCommissionModel;
use CodeIgniter\Entity\Entity;

class IntegrationCommission extends Entity
{
    protected $casts = [
        'id'                     => 'integer',
        'account_id'             => 'integer',
        'lead_id'                => 'integer',
        'property_id'            => '?integer',
        'rule_id'                => '?integer',
        'payment_transaction_id' => '?integer',
        'base_value'             => 'float',
        'commission_value'       => 'float',
    ];

    protected $dates = ['closed_at', 'approved_at', 'invoiced_at', 'paid_at', 'created_at', 'updated_at'];

    public function statusLabel(): string
    {
        return match ($this->attributes['status'] ?? '') {
            IntegrationCommissionModel::STATUS_PENDING   => 'Aguardando aprovação',
            IntegrationCommissionModel::STATUS_APPROVED  => 'Aprovada',
            IntegrationCommissionModel::STATUS_INVOICED  => 'Faturada',
            IntegrationCommissionModel::STATUS_PAID      => 'Paga',
            IntegrationCommissionModel::STATUS_CANCELLED => 'Cancelada',
            default                                      => (string) ($this->attributes['status'] ?? ''),
        };
    }

    public function statusBadge(): string
    {
        return match ($this->attributes['status'] ?? '') {
            IntegrationCommissionModel::STATUS_PENDING   => 'secondary',
            IntegrationCommissionModel::STATUS_APPROVED  => 'info',
            IntegrationCommissionModel::STATUS_INVOICED  => 'warning',
            IntegrationCommissionModel::STATUS_PAID      => 'success',
            IntegrationCommissionModel::STATUS_CANCELLED => 'dark',
            default                                      => 'secondary',
        };
    }

    /** Só o que ainda não virou cobrança pode ser alterado pelo superadmin. */
    public function isEditable(): bool
    {
        return in_array(
            $this->attributes['status'] ?? '',
            [IntegrationCommissionModel::STATUS_PENDING, IntegrationCommissionModel::STATUS_APPROVED],
            true
        );
    }
}
