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

    public static function fromIntegration(\App\Entities\AccountIntegration $integration, bool $forceFull = false): self
    {
        if ($forceFull) {
            return new self(null, true);
        }

        $since = $integration->last_sync_at;

        if ($since instanceof \CodeIgniter\I18n\Time) {
            $since = $since->toDateTimeString();
        }

        return new self($since === null ? null : (string) $since, $since === null);
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
