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
     * Mesma regra de `effectiveLevel()`, em PHP — para quando a linha já foi
     * lida do banco (pin do mapa, card de listagem) e não dá pra usar uma
     * expressão SQL. `$expiresAt` aceita string (formato do Postgres) ou algo
     * já convertido para timestamp.
     */
    public static function isEffectivelyActiveValue(?int $level, $expiresAt): bool
    {
        if (empty($level) || $level <= 0 || empty($expiresAt)) {
            return false;
        }

        $expiresTimestamp = is_numeric($expiresAt) ? (int) $expiresAt : strtotime((string) $expiresAt);

        return $expiresTimestamp !== false && $expiresTimestamp > time();
    }

    /**
     * "Tem selo Patrocinado nesta própria exibição" — a mesma regra de
     * elegibilidade (editorial OU turbo vigente), mas SEM o filtro de feature
     * do plano: um Prata que comprou turbinada avulsa mostra o selo no seu
     * próprio card (só não ocupa slot na busca de outra conta — isso é
     * decidido à parte, em `SponsoredPlacementService`).
     *
     * Quando a linha já passou pelo merge de slots (`is_sponsored` presente,
     * true ou false), o CHAMADOR deve usar aquele valor em vez deste método —
     * é o que marca exatamente quem ocupa um dos 3 slots da página 1, e um
     * item fora do slot não pode "recalcular" o selo por conta própria (era
     * exatamente o bug que isto corrige).
     */
    public static function isSponsoredDisplay(bool $isDestaque, ?int $level, $expiresAt): bool
    {
        return $isDestaque || self::isEffectivelyActiveValue($level, $expiresAt);
    }

    /**
     * Predicado para "tem destaque pago vigente".
     */
    public static function isActive(string $alias = 'properties'): string
    {
        return self::effectiveLevel($alias) . ' > 0';
    }

    /**
     * Predicado de elegibilidade a slot patrocinado (Fase 2) e à prateleira de
     * destaque da home — o MESMO critério nos dois lugares, de propósito.
     *
     * Duas portas, sem meio-termo:
     * - `is_destaque = true`: curadoria EDITORIAL da Habitaweb, independente de
     *   plano. Não é à venda — é o próprio comentário de PropertyService sobre
     *   o campo: "selo editorial exclusivo da Habitaweb".
     * - turbo vigente **E** a conta tem a feature `exposicao.busca`: uma
     *   imobiliária Prata pode comprar turbinada avulsa (ganha o badge
     *   "Patrocinado" na própria página, entra na prateleira do imóvel), mas
     *   NÃO ocupa slot no resultado de busca de outra pessoa — a proposta
     *   comercial reserva "áreas de imóveis destacados dentro dos resultados"
     *   para Ouro/Diamante. Sem a feature, turbo vigente sozinho NÃO basta.
     *
     * `plans.features->>'exposicao.busca'` extrai como texto; `IS TRUE` numa
     * conversão de NULL (plano sem a chave, ou sem plano — LEFT JOIN) dá
     * `false`, nunca erro.
     */
    public static function sponsorshipEligible(string $propertiesAlias = 'properties', string $plansAlias = 'plans'): string
    {
        return "({$propertiesAlias}.is_destaque = true OR ("
             . self::isActive($propertiesAlias)
             . " AND ({$plansAlias}.features->>'exposicao.busca')::boolean IS TRUE))";
    }

    /**
     * Peso de ordenação DENTRO do grupo de elegíveis (Lane B / prateleira de
     * destaque) — nunca multiplicador do ranking orgânico. Editorial e turbo
     * pesam igual na prioridade de topo (1000); `exposure_weight` do plano
     * desempata entre concorrentes turbinados/curados na mesma janela.
     */
    public static function sponsorshipWeight(string $propertiesAlias = 'properties', string $plansAlias = 'plans'): string
    {
        return '((CASE WHEN ' . "{$propertiesAlias}.is_destaque = true OR " . self::isActive($propertiesAlias)
             . " THEN 1000 ELSE 0 END) + COALESCE({$plansAlias}.exposure_weight, 0))";
    }
}
