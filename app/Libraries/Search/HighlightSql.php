<?php

namespace App\Libraries\Search;

/**
 * Nível de destaque *efetivo* de um imóvel, em SQL.
 *
 * `properties.highlight_level` e `highlight_expires_at` são denormalizações
 * gravadas quando a turbinada é paga. A limpeza depende do cron `promo:cleanup`,
 * que zera o nível depois do vencimento — mas nenhuma query de leitura conferia
 * a data. Consequência: cron atrasado, caído ou simplesmente não agendado (era o
 * caso) e a exposição paga continuava valendo indefinidamente, de graça.
 *
 * Vendendo turbinada por prazo de 7 dias, isso deixa de ser detalhe: é entregar
 * mais do que foi vendido. Aqui o vencimento passa a valer no instante da
 * consulta, e o cron vira apenas higiene de dados.
 *
 * O índice idx_properties_highlight(highlight_level, highlight_expires_at) criado
 * em 2026-01-16-110000 cobre as duas colunas usadas na expressão.
 */
final class HighlightSql
{
    /**
     * Expressão SQL que devolve 0 para destaque vencido e o nível real para
     * destaque vigente.
     *
     * @param string $alias Alias/tabela dos imóveis na query.
     */
    public static function effectiveLevel(string $alias = 'properties'): string
    {
        return "(CASE WHEN {$alias}.highlight_expires_at IS NOT NULL"
             . " AND {$alias}.highlight_expires_at > NOW()"
             . " THEN COALESCE({$alias}.highlight_level, 0) ELSE 0 END)";
    }

    /**
     * Predicado para "tem destaque pago vigente".
     */
    public static function isActive(string $alias = 'properties'): string
    {
        return self::effectiveLevel($alias) . ' > 0';
    }
}
