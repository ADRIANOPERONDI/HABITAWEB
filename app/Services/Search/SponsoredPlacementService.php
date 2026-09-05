<?php

namespace App\Services\Search;

/**
 * Insere resultados patrocinados em posições fixas dentro de uma página
 * orgânica já pronta — a peça que faz o cliente conseguir dizer "Diamante
 * paga mais, mas nunca empurra um imóvel fora do filtro para a frente".
 *
 * Deliberadamente burro: não sabe nada de plano, turbo, feature ou SQL. Só
 * sabe misturar duas listas. Quem decide QUEM é elegível é
 * `App\Libraries\Search\HighlightSql::sponsorshipEligible()`, aplicado sobre
 * o MESMO `WHERE` da lane orgânica (`PropertyService::applySearchFilters`) —
 * por isso um candidato patrocinado NUNCA pode estar fora do que o visitante
 * pediu: ele foi buscado com o filtro idêntico, só que numa query menor.
 */
final class SponsoredPlacementService
{
    /**
     * Posições 0-indexed onde um patrocinado pode ocupar espaço — "1ª, 6ª e
     * 11ª posição do resultado", só na página 1.
     */
    private const SLOT_POSITIONS = [0, 5, 10];

    /** Quantos candidatos a lane B precisa buscar — nunca mais que os slots. */
    public const SLOT_COUNT = 3;

    /**
     * @param list<object> $organicos    Página orgânica já paginada, ordem final.
     * @param list<object> $patrocinados Candidatos elegíveis, já ordenados
     *                                   (peso de patrocínio desc, score desc).
     *                                   Só os primeiros `count(SLOT_POSITIONS)`
     *                                   são usados; passar mais que isso é
     *                                   inofensivo, só desperdiça query.
     *
     * @return list<object> Lista mesclada, com `is_sponsored` marcado em
     *                       TODO item (true nos inseridos, false nos demais)
     *                       — nunca deixa o chamador reconstruir isso na mão.
     */
    public function merge(array $organicos, array $patrocinados, int $page): array
    {
        foreach ($organicos as $item) {
            $item->is_sponsored = false;
        }

        // Fora da página 1, ou sem ninguém elegível: devolve a lane orgânica
        // intacta. É o "colapsa em orgânico" que a garantia ao cliente exige —
        // slot vazio nunca vira imóvel fora do filtro só para preencher espaço.
        if ($page !== 1 || $patrocinados === []) {
            return $organicos;
        }

        $idsPatrocinados = array_map(static fn ($p) => (int) $p->id, $patrocinados);

        // Tira da lane orgânica quem também está entre os patrocinados desta
        // página — sem isso, o mesmo imóvel apareceria duas vezes quando ele
        // já rankeava bem organicamente E também tinha turbo ativo.
        $resultado = array_values(array_filter(
            $organicos,
            static fn ($item) => ! in_array((int) $item->id, $idsPatrocinados, true)
        ));

        $fila = array_slice($patrocinados, 0, count(self::SLOT_POSITIONS));

        foreach (self::SLOT_POSITIONS as $posicao) {
            if ($fila === []) {
                break;
            }

            // Página curta (menos itens que a posição do próximo slot): as
            // posições seguintes são ainda mais distantes, não adianta tentar.
            if ($posicao > count($resultado)) {
                break;
            }

            $candidato = array_shift($fila);
            $candidato->is_sponsored = true;
            array_splice($resultado, $posicao, 0, [$candidato]);
        }

        return $resultado;
    }
}
