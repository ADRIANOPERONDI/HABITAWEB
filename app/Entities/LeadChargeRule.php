<?php

namespace App\Entities;

use App\Models\LeadChargeRuleModel;
use CodeIgniter\Entity\Entity;

class LeadChargeRule extends Entity
{
    protected $casts = [
        'id'         => 'integer',
        'account_id' => '?integer',
        'is_active'  => 'boolean',
    ];

    /**
     * Aplica a regra sobre o valor base (closing_value para negócio fechado;
     * 0 para lead recebido, onde o modelo é sempre FIXED).
     *
     * Piso e teto entram DEPOIS do cálculo, nesta ordem: o teto tem a última
     * palavra, para que um piso mal configurado acima do teto não gere cobrança
     * maior que o combinado.
     */
    public function calculate(float $baseValue): float
    {
        $model = (string) ($this->attributes['model'] ?? LeadChargeRuleModel::MODEL_PERCENT);
        $value = (float) ($this->attributes['value'] ?? 0);

        $commission = $model === LeadChargeRuleModel::MODEL_FIXED
            ? $value
            : $baseValue * ($value / 100);

        $min = $this->attributes['min_value'] ?? null;
        $max = $this->attributes['max_value'] ?? null;

        if ($min !== null) {
            $commission = max($commission, (float) $min);
        }

        if ($max !== null) {
            $commission = min($commission, (float) $max);
        }

        return round(max(0, $commission), 2);
    }

    public function describe(): string
    {
        $model = (string) ($this->attributes['model'] ?? '');
        $value = (float) ($this->attributes['value'] ?? 0);

        $base = $model === LeadChargeRuleModel::MODEL_FIXED
            ? 'R$ ' . number_format($value, 2, ',', '.')
            : rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',') . '%';

        $limites = [];

        if (! empty($this->attributes['min_value'])) {
            $limites[] = 'mín. R$ ' . number_format((float) $this->attributes['min_value'], 2, ',', '.');
        }

        if (! empty($this->attributes['max_value'])) {
            $limites[] = 'máx. R$ ' . number_format((float) $this->attributes['max_value'], 2, ',', '.');
        }

        return $base . ($limites === [] ? '' : ' (' . implode(', ', $limites) . ')');
    }
}
