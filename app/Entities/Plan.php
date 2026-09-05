<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class Plan extends Entity
{
    protected $datamap = [];
    protected $dates   = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * Os casts que importam estão em PlanModel::$casts, não aqui.
     *
     * O cast da *entity* não se aplica ao que o model lê do banco — e o Postgres
     * devolve boolean como 't'/'f', string em que 'f' é truthy no PHP. Este
     * bloco fica vazio de propósito, para não dar a impressão de que resolve.
     */
    protected $casts = [];

    /**
     * O plano concede esta feature?
     *
     * Tolerante à ausência: plano antigo, sem a chave em `features`, responde
     * false em vez de estourar. O default do banco é '{}' justamente para que
     * "não configurado" signifique "não tem".
     */
    public function has(string $feature): bool
    {
        $features = $this->attributes['features'] ?? [];

        if (is_string($features)) {
            $features = json_decode($features, true) ?: [];
        }

        return (bool) ($features[$feature] ?? false);
    }

    /** @return string[] Features ativas, só as reconhecidas pelo catálogo. */
    public function activeFeatures(): array
    {
        return array_values(array_filter(PlanFeature::all(), fn ($f) => $this->has($f)));
    }

    /**
     * Turbinadas incluídas por mês. null = ilimitado, 0 = nenhuma.
     *
     * Lê `limite_turbo_mensal`, que é o campo realmente aplicado pelo sistema —
     * `destaques_mensais` só servia a uma trava de downgrade e a texto de tela.
     */
    public function turbosIncluidos(): ?int
    {
        $limite = $this->attributes['limite_turbo_mensal'] ?? null;

        return $limite === null || $limite === '' ? null : (int) $limite;
    }

    /** Turbinadas extras por mês concedidas no ciclo anual. */
    public function turboBonusAnual(): int
    {
        return (int) ($this->attributes['turbo_bonus_anual'] ?? 0);
    }

    public function creditoLeadsMensal(): float
    {
        return (float) ($this->attributes['credito_leads_mensal'] ?? 0);
    }

    public function exposureWeight(): int
    {
        return (int) ($this->attributes['exposure_weight'] ?? 0);
    }

    public function isIlimitadoImoveis(): bool
    {
        $limite = $this->attributes['limite_imoveis_ativos'] ?? null;

        return $limite === null || $limite === '';
    }
}
