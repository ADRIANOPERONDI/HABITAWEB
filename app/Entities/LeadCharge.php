<?php

namespace App\Entities;

use App\Models\LeadChargeModel;
use CodeIgniter\Entity\Entity;

class LeadCharge extends Entity
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
        'credit_applied'         => 'float',
    ];

    protected $dates = [
        'closed_at', 'approved_at', 'invoiced_at', 'paid_at', 'contest_deadline',
        'disputed_at', 'dispute_resolved_at', 'created_at', 'updated_at',
    ];

    public function statusLabel(): string
    {
        return self::labelFor((string) ($this->attributes['status'] ?? ''));
    }

    /**
     * Mesmo rótulo de statusLabel(), mas sem precisar de uma entity — usado
     * pelos totais agrupados por status (`totalsByStatus()`), que trafegam
     * como string crua, não como LeadCharge.
     */
    public static function labelFor(string $status): string
    {
        return match ($status) {
            LeadChargeModel::STATUS_PENDING   => 'Aguardando aprovação',
            LeadChargeModel::STATUS_APPROVED  => 'Aprovada',
            LeadChargeModel::STATUS_DISPUTED  => 'Contestada',
            LeadChargeModel::STATUS_INVOICED  => 'Faturada',
            LeadChargeModel::STATUS_PAID      => 'Paga',
            LeadChargeModel::STATUS_CANCELLED => 'Cancelada',
            LeadChargeModel::STATUS_WAIVED    => 'Isentada',
            default                           => $status,
        };
    }

    public function statusBadge(): string
    {
        return match ($this->attributes['status'] ?? '') {
            LeadChargeModel::STATUS_PENDING   => 'secondary',
            LeadChargeModel::STATUS_APPROVED  => 'info',
            LeadChargeModel::STATUS_DISPUTED  => 'danger',
            LeadChargeModel::STATUS_INVOICED  => 'warning',
            LeadChargeModel::STATUS_PAID      => 'success',
            LeadChargeModel::STATUS_CANCELLED => 'dark',
            LeadChargeModel::STATUS_WAIVED    => 'light',
            default                           => 'secondary',
        };
    }

    /** Só o que ainda não virou cobrança pode ser alterado pelo superadmin. */
    public function isEditable(): bool
    {
        return in_array(
            $this->attributes['status'] ?? '',
            [LeadChargeModel::STATUS_PENDING, LeadChargeModel::STATUS_APPROVED],
            true
        );
    }

    /** O tenant ainda pode contestar esta cobrança. */
    public function isContestable(): bool
    {
        if (($this->attributes['status'] ?? '') !== LeadChargeModel::STATUS_PENDING) {
            return false;
        }

        $deadline = $this->attributes['contest_deadline'] ?? null;

        return $deadline !== null && strtotime((string) $deadline) > time();
    }
}
