<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Comissões apuradas sobre leads fechados de imóveis integrados.
 */
class IntegrationCommissionModel extends Model
{
    protected $table            = 'integration_commissions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\IntegrationCommission::class;
    protected $allowedFields    = [
        'account_id', 'provider_code', 'lead_id', 'property_id', 'rule_id',
        'tipo_negocio', 'base_value', 'commission_value', 'status',
        'payment_transaction_id', 'closed_at', 'approved_at', 'invoiced_at', 'paid_at', 'notes',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public const STATUS_PENDING   = 'PENDING';
    public const STATUS_APPROVED  = 'APPROVED';
    public const STATUS_INVOICED  = 'INVOICED';
    public const STATUS_PAID      = 'PAID';
    public const STATUS_CANCELLED = 'CANCELLED';

    public function findByLead(int $leadId): ?\App\Entities\IntegrationCommission
    {
        return $this->where('lead_id', $leadId)->first();
    }

    /**
     * Lista com filtros, para a tela do superadmin.
     *
     * @return array{items: \App\Entities\IntegrationCommission[], pager: mixed}
     */
    public function listFiltered(array $filters = [], int $perPage = 25): array
    {
        $builder = $this->select('integration_commissions.*, accounts.nome as account_name')
            ->join('accounts', 'accounts.id = integration_commissions.account_id', 'left');

        if (! empty($filters['account_id'])) {
            $builder->where('integration_commissions.account_id', (int) $filters['account_id']);
        }

        if (! empty($filters['status'])) {
            $builder->where('integration_commissions.status', $filters['status']);
        }

        if (! empty($filters['from'])) {
            $builder->where('integration_commissions.closed_at >=', $filters['from'] . ' 00:00:00');
        }

        if (! empty($filters['to'])) {
            $builder->where('integration_commissions.closed_at <=', $filters['to'] . ' 23:59:59');
        }

        $items = $builder->orderBy('integration_commissions.closed_at', 'DESC')->paginate($perPage);

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

    /** @return \App\Entities\IntegrationCommission[] */
    public function approvedFor(int $accountId): array
    {
        return $this->where('account_id', $accountId)
            ->where('status', self::STATUS_APPROVED)
            ->findAll();
    }

    /** Contas com comissão aprovada esperando faturamento. */
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

    public function markStatus(int $id, string $status, array $extra = []): bool
    {
        $stamp = match ($status) {
            self::STATUS_APPROVED => ['approved_at' => date('Y-m-d H:i:s')],
            self::STATUS_INVOICED => ['invoiced_at' => date('Y-m-d H:i:s')],
            self::STATUS_PAID     => ['paid_at' => date('Y-m-d H:i:s')],
            default               => [],
        };

        return (bool) $this->update($id, array_merge(['status' => $status], $stamp, $extra));
    }
}
