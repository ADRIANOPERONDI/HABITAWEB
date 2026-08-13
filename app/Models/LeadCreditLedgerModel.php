<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Ledger append-only da carteira de créditos de lead. Ver
 * `App\Services\LeadCreditService` para as regras de negócio — este model é
 * só leitura/escrita de linhas.
 */
class LeadCreditLedgerModel extends Model
{
    protected $table            = 'lead_credit_ledger';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $allowedFields    = [
        'account_id', 'tipo', 'origem', 'amount', 'periodo', 'reference_type', 'reference_id', 'notes',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';

    protected array $casts = [
        'account_id'   => 'integer',
        'amount'       => 'float',
        'reference_id' => '?integer',
    ];

    public const TIPO_CREDITO = 'CREDITO';
    public const TIPO_DEBITO  = 'DEBITO';

    public function balanceFor(int $accountId, string $periodo): float
    {
        $row = $this->builder()
            ->select("
                COALESCE(SUM(CASE WHEN tipo = 'CREDITO' THEN amount ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN tipo = 'DEBITO' THEN amount ELSE 0 END), 0) as saldo
            ", false)
            ->where('account_id', $accountId)
            ->where('periodo', $periodo)
            ->get()
            ->getRow();

        return round((float) ($row->saldo ?? 0), 2);
    }

    /** @return array{items: object[], pager: mixed} */
    public function listFor(int $accountId, int $perPage = 25): array
    {
        $items = $this->where('account_id', $accountId)
            ->orderBy('periodo', 'DESC')
            ->orderBy('created_at', 'DESC')
            ->paginate($perPage);

        return ['items' => $items, 'pager' => $this->pager];
    }
}
