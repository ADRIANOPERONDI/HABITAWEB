<?php

namespace App\Libraries\Integrations\Dto;

/**
 * Até onde o sync anterior chegou.
 *
 * A API do Simob não tem filtro `updated_since`. O incremental se faz pedindo
 * o catálogo ordenado por `atualizacao desc` e parando de paginar assim que
 * aparece um imóvel com updatedAt <= o corte. Daí este DTO carregar um
 * `since` e não um offset.
 *
 * `since` nulo = primeira execução, varre o catálogo inteiro.
 */
final class SyncCursor
{
    public function __construct(
        public readonly ?string $since = null,
        public readonly bool $full = false,
    ) {
    }

    /** Folga do corte incremental contra deriva de relógio entre este servidor e a origem. */
    private const CLOCK_SKEW_MARGIN_SECONDS = 24 * 60 * 60;

    public static function fromIntegration(\App\Entities\AccountIntegration $integration, bool $forceFull = false): self
    {
        if ($forceFull) {
            return new self(null, true);
        }

        $since = $integration->last_sync_at;

        if ($since instanceof \CodeIgniter\I18n\Time) {
            $since = $since->toDateTimeString();
        }

        if ($since === null) {
            return new self(null, true);
        }

        // Sem esta margem, o relógio deste servidor adiantado (ou atrasado)
        // em relação ao da origem faz o corte incremental descartar, em
        // silêncio, um item que a origem só terminou de gravar depois do
        // instante que registramos como "início" da rodada anterior — ele
        // nunca mais aparece pra ser buscado de novo.
        $comMargem = strtotime((string) $since) - self::CLOCK_SKEW_MARGIN_SECONDS;

        return new self(date('Y-m-d H:i:s', $comMargem), false);
    }

    /**
     * O item da origem é anterior ao corte e portanto já foi sincronizado?
     *
     * Comparação por timestamp para não depender do formato exato da data —
     * o Simob devolve 'Y-m-d H:i:s'.
     */
    public function isBefore(?string $externalUpdatedAt): bool
    {
        if ($this->since === null || $externalUpdatedAt === null || $externalUpdatedAt === '') {
            return false;
        }

        $item = strtotime($externalUpdatedAt);
        $cut  = strtotime($this->since);

        if ($item === false || $cut === false) {
            return false;
        }

        return $item <= $cut;
    }
}
