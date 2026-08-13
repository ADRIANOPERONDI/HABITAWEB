<?php

namespace App\Services;

use App\Entities\Property;

class RankingService
{
    /**
     * Calcula o Score de Qualidade (0 a 100) para um imóvel.
     *
     * Critérios (delegados a CurationService::calculateDetailedScore):
     * - Fotos: +10 pontos por foto (max 50)
     * - Descrição: +10 se > 200 caracteres
     * - Endereço completo (Rua + Num): +10
     * - Características: +5 por item (max 20)
     *
     * Este score mede SÓ a qualidade do anúncio, nunca quanto o anunciante pagou.
     *
     * Havia aqui um bloco que somava +200 a +1000 por promoção ativa. Era errado
     * em dois níveis. Primeiro quebrava a escala: o valor é gravado em
     * `properties.score_qualidade`, exibido como nota 0–100 no painel e na
     * curadoria — um imóvel promovido aparecia com 1043. Segundo, e pior, as
     * fórmulas de ordenação usam o score como MULTIPLICADOR (`score/100`): com
     * 1043 o imóvel multiplicava a própria relevância por 10, em vez do fator
     * ≤ 1 que a fórmula pressupõe. Ou seja, o boost não somava exposição, ele
     * multiplicava — e o efeito era invisível para quem lesse só a fórmula.
     *
     * A exposição paga vive em `highlight_level`/`highlight_expires_at`, que as
     * consultas já consideram via App\Libraries\Search\HighlightSql.
     */
    public function calculateScore(Property $property): int
    {
        $mediaModel = model('App\Models\PropertyMediaModel');
        $mediaCount = $mediaModel->countByProperty($property->id);

        $curationService = new \App\Services\CurationService();
        $result = $curationService->calculateDetailedScore($property, $mediaCount);

        return $result['score'];
    }

    /**
     * Atualiza o score no banco de dados.
     *
     * Debounce de 30s por imóvel: updateScore é chamado a CADA foto enviada
     * (upload de 20 fotos = 20 recálculos, ~4 queries cada). Na janela do
     * debounce, o recálculo é adiado para o cron (spark metrics:flush) via
     * marcador no Redis — o score fica correto ao final do lote com 1 cálculo
     * imediato + 1 diferido. Se o Redis estiver fora, executa síncrono como
     * antes (o debounce só é pulado quando dá pra adiar com segurança).
     *
     * @param bool $force true = ignora o debounce (usado pelo flusher).
     */
    public function updateScore(int $propertyId, bool $force = false)
    {
        if (! $force) {
            $recentKey = "ranking_recent_{$propertyId}";

            if (cache($recentKey) !== null) {
                // Recalculado há <30s: adia pro flusher — mas só se o marcador
                // "sujo" puder ser gravado; sem Redis, calcula síncrono.
                if (service('metricsBuffer')->markRankingDirty($propertyId)) {
                    return;
                }
            } else {
                cache()->save($recentKey, 1, 30);
            }
        }

        $propertyModel = model('App\Models\PropertyModel');
        $property = $propertyModel->find($propertyId);

        if (!$property) return;

        $newScore = $this->calculateScore($property);

        $propertyModel->update($propertyId, ['score_qualidade' => $newScore]);
    }
}
