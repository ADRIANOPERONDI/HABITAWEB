<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Cobranças por lead — recebido ou, no histórico anterior à Fase 3, fechado
 * com sucesso em imóvel vindo de integração. Ver `origem`.
 */
class LeadChargeModel extends Model
{
    protected $table            = 'lead_charges';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\LeadCharge::class;
    protected $allowedFields    = [
        'account_id', 'provider_code', 'lead_id', 'property_id', 'rule_id',
        'tipo_negocio', 'origem', 'periodo', 'base_value', 'commission_value', 'status',
        'payment_transaction_id', 'credit_applied', 'contest_deadline',
        'dispute_reason', 'disputed_at', 'dispute_resolved_at', 'waived_reason',
        'closed_at', 'approved_at', 'invoiced_at', 'paid_at', 'notes',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public const STATUS_PENDING   = 'PENDING';
    public const STATUS_APPROVED  = 'APPROVED';
    public const STATUS_DISPUTED  = 'DISPUTED';
    public const STATUS_INVOICED  = 'INVOICED';
    public const STATUS_PAID      = 'PAID';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_WAIVED    = 'WAIVED';

    public const ORIGEM_LEAD_RECEBIDO  = 'LEAD_RECEBIDO';
    public const ORIGEM_NEGOCIO_FECHADO = 'NEGOCIO_FECHADO';

    public function findByLead(int $leadId): ?\App\Entities\LeadCharge
    {
        return $this->where('lead_id', $leadId)->first();
    }

    /**
     * Lista com filtros, para a tela do superadmin.
     *
     * @return array{items: \App\Entities\LeadCharge[], pager: mixed}
     */
    public function listFiltered(array $filters = [], int $perPage = 25): array
    {
        $builder = $this->select('lead_charges.*, accounts.nome as account_name')
            ->join('accounts', 'accounts.id = lead_charges.account_id', 'left');

        if (! empty($filters['account_id'])) {
            $builder->where('lead_charges.account_id', (int) $filters['account_id']);
        }

        if (! empty($filters['status'])) {
            $builder->where('lead_charges.status', $filters['status']);
        }

        if (! empty($filters['from'])) {
            $builder->where('lead_charges.created_at >=', $filters['from'] . ' 00:00:00');
        }

        if (! empty($filters['to'])) {
            $builder->where('lead_charges.created_at <=', $filters['to'] . ' 23:59:59');
        }

        $items = $builder->orderBy('lead_charges.created_at', 'DESC')->paginate($perPage);

        return ['items' => $items, 'pager' => $this->pager];
    }

    /**
     * Totais por status, para o cabeçalho da tela.
     *
     * @return array<string, array{count:int, total:float}>
     */
    public function totalsByStatus(array $filters = []): array
    {
        $builder = $this->builder()
            ->select('status, COUNT(*) as qtd, COALESCE(SUM(commission_value), 0) as total')
            ->groupBy('status');

        if (! empty($filters['account_id'])) {
            $builder->where('account_id', (int) $filters['account_id']);
        }

        $out = [];

        foreach ($builder->get()->getResultArray() as $row) {
            $out[$row['status']] = [
                'count' => (int) $row['qtd'],
                'total' => (float) $row['total'],
            ];
        }

        return $out;
    }

    /** @return \App\Entities\LeadCharge[] */
    public function approvedFor(int $accountId): array
    {
        return $this->where('account_id', $accountId)
            ->where('status', self::STATUS_APPROVED)
            ->findAll();
    }

    /** Aprovadas de uma conta num período (mês de competência), para o fechamento de ciclo. */
    public function approvedForPeriod(int $accountId, string $periodo): array
    {
        return $this->where('account_id', $accountId)
            ->where('status', self::STATUS_APPROVED)
            ->where('periodo', $periodo)
            ->findAll();
    }

    /** Contas com cobrança aprovada esperando faturamento. */
    public function accountsWithApproved(): array
    {
        $rows = $this->builder()
            ->select('account_id')
            ->where('status', self::STATUS_APPROVED)
            ->groupBy('account_id')
            ->get()
            ->getResultArray();

        return array_map(static fn ($r) => (int) $r['account_id'], $rows);
    }

    /** PENDING cujo prazo de contestação já passou — candidatas à aprovação automática. */
    public function pendingPastDeadline(?string $onDate = null): array
    {
        $onDate ??= date('Y-m-d H:i:s');

        return $this->where('status', self::STATUS_PENDING)
            ->where('contest_deadline IS NOT NULL')
            ->where('contest_deadline <=', $onDate)
            ->findAll();
    }

    public function markStatus(int $id, string $status, array $extra = []): bool
    {
        $stamp = match ($status) {
            self::STATUS_APPROVED => ['approved_at' => date('Y-m-d H:i:s')],
            self::STATUS_INVOICED => ['invoiced_at' => date('Y-m-d H:i:s')],
            self::STATUS_PAID     => ['paid_at' => date('Y-m-d H:i:s')],
            self::STATUS_DISPUTED => ['disputed_at' => date('Y-m-d H:i:s')],
            default               => [],
        };

        return (bool) $this->update($id, array_merge(['status' => $status], $stamp, $extra));
    }
}
