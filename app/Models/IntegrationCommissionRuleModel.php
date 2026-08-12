<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Regras de comissão por lead fechado.
 */
class IntegrationCommissionRuleModel extends Model
{
    protected $table            = 'integration_commission_rules';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\IntegrationCommissionRule::class;
    protected $allowedFields    = [
        'account_id', 'provider_code', 'tipo_negocio', 'model', 'value',
        'min_value', 'max_value', 'is_active', 'valid_from', 'valid_to', 'notes',
    ];

    protected array $casts = [
        'is_active' => 'boolean',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public const MODEL_PERCENT = 'PERCENT';
    public const MODEL_FIXED   = 'FIXED';

    /**
     * A regra mais específica que se aplica ao caso.
     *
     * Ordem de precedência, da mais específica para a mais genérica:
     *   1. regra do tenant para aquele tipo de negócio;
     *   2. regra do tenant para qualquer tipo;
     *   3. regra padrão da plataforma para aquele tipo;
     *   4. regra padrão da plataforma para qualquer tipo.
     *
     * Sem ordenação explícita, o banco poderia devolver a genérica antes da
     * específica e o tenant seria cobrado pela taxa errada.
     */
    public function resolveFor(int $accountId, string $providerCode, ?string $tipoNegocio, ?string $onDate = null): ?\App\Entities\IntegrationCommissionRule
    {
        $onDate ??= date('Y-m-d');

        $rules = $this->where('is_active', true)
            ->groupStart()
                ->where('account_id', $accountId)
                ->orWhere('account_id', null)
            ->groupEnd()
            ->groupStart()
                ->where('provider_code', $providerCode)
                ->orWhere('provider_code', null)
            ->groupEnd()
            ->groupStart()
                ->where('tipo_negocio', $tipoNegocio)
                ->orWhere('tipo_negocio', null)
            ->groupEnd()
            ->groupStart()
                ->where('valid_from <=', $onDate)
                ->orWhere('valid_from', null)
            ->groupEnd()
            ->groupStart()
                ->where('valid_to >=', $onDate)
                ->orWhere('valid_to', null)
            ->groupEnd()
            ->findAll();

        if ($rules === []) {
            return null;
        }

        // A especificidade é decidida em PHP: montar isso em SQL portável
        // (CASE WHEN dentro de ORDER BY, com NULLs) fica ilegível e muda de
        // comportamento entre Postgres e MySQL.
        usort($rules, static function ($a, $b) {
            $score = static fn ($r) => ($r->account_id !== null ? 4 : 0)
                + ($r->tipo_negocio !== null ? 2 : 0)
                + ($r->provider_code !== null ? 1 : 0);

            return $score($b) <=> $score($a);
        });

        return $rules[0];
    }

    /** @return \App\Entities\IntegrationCommissionRule[] */
    public function listAll(): array
    {
        return $this->orderBy('account_id', 'ASC')->orderBy('tipo_negocio', 'ASC')->findAll();
    }
}
