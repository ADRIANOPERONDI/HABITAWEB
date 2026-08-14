<?php

namespace App\Libraries\Metrics;

/**
 * Buffer de métricas em Redis usando a extensão \Redis diretamente (não o
 * cache handler do CI4).
 *
 * Por que não o cache handler: o RedisHandler do CI4 grava valores como hash
 * tipado (__ci_type/__ci_value); uma chave criada só por increment() fica
 * ilegível por get() e não há como ENUMERAR chaves pela API do cache — e o
 * flusher precisa exatamente disso (listar quais imóveis têm visitas
 * pendentes). Conexão própria resolve, lendo a MESMA config do .env
 * (cache.redis.*), mas com prefixo de chave próprio (hw:metrics:) para nunca
 * colidir com o cache — e sobreviver a um cache()->clean() (FLUSHDB) apenas
 * se estiver em DB separado; como usamos o mesmo DB do cache, o pior caso de
 * um clean() é perder contadores ainda não flushados (aceitável para
 * contagem de visitas, e o clean() só roda em comandos administrativos).
 *
 * TODA operação é fail-open: Redis indisponível => retorna false e o chamador
 * usa o caminho síncrono antigo (UPDATE direto / cálculo imediato).
 */
class RedisMetricsBuffer
{
    private const PREFIX        = 'hw:metrics:';
    private const VISITS_SET    = self::PREFIX . 'visits:pending';
    private const VISITS_KEY    = self::PREFIX . 'visits:';
    private const RANKING_SET   = self::PREFIX . 'ranking:dirty';

    // Série diária de visualização de imóvel (Fase 4). Chave de contador:
    // "{dia}:{propertyId}"; chave de origem: "{dia}:{propertyId}:{origem}".
    private const PV_DIRTY_SET    = self::PREFIX . 'pv:dirty';
    private const PV_VIEWS_KEY    = self::PREFIX . 'pv:views:';
    private const PV_UNICAS_KEY   = self::PREFIX . 'pv:unicas:';
    private const PV_SEEN_KEY     = self::PREFIX . 'pv:seen:';
    private const PVSRC_DIRTY_SET = self::PREFIX . 'pvsrc:dirty';
    private const PVSRC_KEY       = self::PREFIX . 'pvsrc:';

    // Série diária de busca (Fase 4). Chave: "{dia}|{tipo_negocio}|{cidade}|
    // {bairro}|{tipo_imovel}|{faixa_preco}" — o próprio pipe-delimited é a
    // chave primária de search_daily.
    private const SEARCH_DIRTY_SET = self::PREFIX . 'search:dirty';
    private const SEARCH_KEY       = self::PREFIX . 'search:';

    private ?\Redis $redis = null;
    private bool $unavailable = false;

    /**
     * Conexão lazy via RedisConnector: uma tentativa por request; falhou,
     * marca indisponível e todas as operações seguintes retornam false sem
     * novo timeout.
     */
    private function redis(): ?\Redis
    {
        if ($this->unavailable) {
            return null;
        }

        if ($this->redis !== null) {
            return $this->redis;
        }

        $redis = \App\Libraries\RedisConnector::make();
        if ($redis === null) {
            $this->unavailable = true;
            return null;
        }

        return $this->redis = $redis;
    }

    /**
     * Registra +1 visita para o imóvel no buffer. false = chamador deve fazer
     * o UPDATE direto no banco (fallback).
     */
    public function bufferVisit(int $propertyId): bool
    {
        $redis = $this->redis();
        if ($redis === null) {
            return false;
        }

        try {
            $redis->multi(\Redis::PIPELINE)
                  ->sAdd(self::VISITS_SET, (string) $propertyId)
                  ->incrBy(self::VISITS_KEY . $propertyId, 1)
                  ->exec();

            return true;
        } catch (\RedisException $e) {
            $this->unavailable = true;
            return false;
        }
    }

    /**
     * Marca um imóvel com score de ranking pendente de recálculo (debounce).
     */
    public function markRankingDirty(int $propertyId): bool
    {
        $redis = $this->redis();
        if ($redis === null) {
            return false;
        }

        try {
            $redis->sAdd(self::RANKING_SET, (string) $propertyId);
            return true;
        } catch (\RedisException $e) {
            $this->unavailable = true;
            return false;
        }
    }

    /**
     * Drena os contadores de visita pendentes, entregando cada (id, contagem)
     * ao callback. Se o callback falhar (retornar false/lançar), a contagem é
     * DEVOLVIDA ao Redis — visitas nunca se perdem por falha do banco.
     *
     * @param callable(int $propertyId, int $count): bool $apply
     * @return int Quantos imóveis foram flushados com sucesso.
     */
    public function flushVisits(callable $apply): int
    {
        $redis = $this->redis();
        if ($redis === null) {
            return 0;
        }

        $flushed = 0;

        foreach ($redis->sMembers(self::VISITS_SET) ?: [] as $id) {
            $key = self::VISITS_KEY . $id;

            // GETDEL: leitura+remoção atômica (Redis >= 6.2) — nenhuma visita
            // registrada entre o GET e o DEL é perdida.
            $count = $redis->getDel($key);
            if ($count === false || (int) $count <= 0) {
                $redis->sRem(self::VISITS_SET, $id);
                continue;
            }

            $ok = false;
            try {
                $ok = $apply((int) $id, (int) $count);
            } catch (\Throwable $e) {
                log_message('error', "[RedisMetricsBuffer] Falha ao aplicar visitas do imóvel {$id}: " . $e->getMessage());
            }

            if ($ok) {
                $redis->sRem(self::VISITS_SET, $id);
                $flushed++;
            } else {
                // Devolve a contagem para a próxima execução do flusher.
                $redis->incrBy($key, (int) $count);
            }
        }

        return $flushed;
    }

    /**
     * Retira e retorna todos os IDs com ranking pendente.
     *
     * @return int[]
     */
    public function popRankingDirty(): array
    {
        $redis = $this->redis();
        if ($redis === null) {
            return [];
        }

        $ids = [];
        while (($id = $redis->sPop(self::RANKING_SET)) !== false) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * Marca uma chave como "já vista" com TTL — SETNX + EX numa chamada só.
     * true = primeira vez (a chave não existia); false = duplicata OU Redis
     * indisponível.
     *
     * Generalista de propósito: usado tanto pelo dedup de visitante único de
     * `bufferPropertyView` (janela de 24h) quanto pelo dedup de pan/zoom do
     * mapa em `SearchMetricsService` (janela de 60s) — a operação Redis é
     * idêntica, só muda a chave e o TTL.
     */
    public function markSeenOnce(string $key, int $ttlSeconds): bool
    {
        $redis = $this->redis();
        if ($redis === null) {
            return false;
        }

        try {
            return (bool) $redis->set($key, 1, ['NX', 'EX' => $ttlSeconds]);
        } catch (\RedisException $e) {
            $this->unavailable = true;
            return false;
        }
    }

    /**
     * Registra uma visualização de imóvel no buffer diário — `views` conta
     * TODA visualização (inclusive F5 repetido); `views_unicas` só conta a
     * primeira por (dia, imóvel, ip+user-agent), via `markSeenOnce` com TTL
     * de 24h. Não mexe em `bufferVisit`/`visits:pending` — o contador
     * denormalizado `properties.visitas_count` continua exatamente como
     * estava.
     */
    public function bufferPropertyView(int $propertyId, string $origem, string $ip, string $userAgent): bool
    {
        $redis = $this->redis();
        if ($redis === null) {
            return false;
        }

        $dia    = date('Y-m-d');
        $dayKey = $dia . ':' . $propertyId;
        $isNew  = $this->markSeenOnce(self::PV_SEEN_KEY . $dayKey . ':' . sha1($ip . '|' . $userAgent), 86400);

        try {
            $multi = $redis->multi(\Redis::PIPELINE);
            $multi->sAdd(self::PV_DIRTY_SET, $dayKey);
            $multi->incrBy(self::PV_VIEWS_KEY . $dayKey, 1);

            if ($isNew) {
                $multi->incrBy(self::PV_UNICAS_KEY . $dayKey, 1);
            }

            $origemKey = $dayKey . ':' . $origem;
            $multi->sAdd(self::PVSRC_DIRTY_SET, $origemKey);
            $multi->incrBy(self::PVSRC_KEY . $origemKey, 1);
            $multi->exec();

            return true;
        } catch (\RedisException $e) {
            $this->unavailable = true;
            return false;
        }
    }

    /**
     * Drena a série diária de visualizações. Mesma disciplina de
     * `flushVisits`: falha do callback devolve a contagem ao Redis.
     *
     * @param callable(int $propertyId, string $dia, int $views, int $viewsUnicas): bool $apply
     */
    public function flushPropertyViews(callable $apply): int
    {
        $redis = $this->redis();
        if ($redis === null) {
            return 0;
        }

        $flushed = 0;

        foreach ($redis->sMembers(self::PV_DIRTY_SET) ?: [] as $dayKey) {
            [$dia, $propertyId] = explode(':', $dayKey, 2);

            $views  = (int) $redis->getDel(self::PV_VIEWS_KEY . $dayKey);
            $unicas = (int) $redis->getDel(self::PV_UNICAS_KEY . $dayKey);

            if ($views <= 0) {
                $redis->sRem(self::PV_DIRTY_SET, $dayKey);
                continue;
            }

            $ok = false;
            try {
                $ok = $apply((int) $propertyId, $dia, $views, $unicas);
            } catch (\Throwable $e) {
                log_message('error', "[RedisMetricsBuffer] Falha ao aplicar views diárias do imóvel {$propertyId} ({$dia}): " . $e->getMessage());
            }

            if ($ok) {
                $redis->sRem(self::PV_DIRTY_SET, $dayKey);
                $flushed++;
            } else {
                $redis->incrBy(self::PV_VIEWS_KEY . $dayKey, $views);
                $redis->incrBy(self::PV_UNICAS_KEY . $dayKey, $unicas);
            }
        }

        return $flushed;
    }

    /**
     * Drena a série diária de visualizações por origem.
     *
     * @param callable(int $propertyId, string $dia, string $origem, int $views): bool $apply
     */
    public function flushPropertyViewSources(callable $apply): int
    {
        $redis = $this->redis();
        if ($redis === null) {
            return 0;
        }

        $flushed = 0;

        foreach ($redis->sMembers(self::PVSRC_DIRTY_SET) ?: [] as $entryKey) {
            [$dia, $propertyId, $origem] = explode(':', $entryKey, 3);

            $views = (int) $redis->getDel(self::PVSRC_KEY . $entryKey);

            if ($views <= 0) {
                $redis->sRem(self::PVSRC_DIRTY_SET, $entryKey);
                continue;
            }

            $ok = false;
            try {
                $ok = $apply((int) $propertyId, $dia, $origem, $views);
            } catch (\Throwable $e) {
                log_message('error', "[RedisMetricsBuffer] Falha ao aplicar views por origem do imóvel {$propertyId} ({$dia}/{$origem}): " . $e->getMessage());
            }

            if ($ok) {
                $redis->sRem(self::PVSRC_DIRTY_SET, $entryKey);
                $flushed++;
            } else {
                $redis->incrBy(self::PVSRC_KEY . $entryKey, $views);
            }
        }

        return $flushed;
    }

    /**
     * Registra uma busca no buffer diário. `$dims` já vem normalizado
     * (strings, com '' para "qualquer") na ordem
     * [tipo_negocio, cidade, bairro, tipo_imovel, faixa_preco] — é
     * literalmente a chave primária de `search_daily`.
     */
    public function bufferSearch(string $dia, array $dims): bool
    {
        $redis = $this->redis();
        if ($redis === null) {
            return false;
        }

        $key = $dia . '|' . implode('|', $dims);

        try {
            $redis->multi(\Redis::PIPELINE)
                  ->sAdd(self::SEARCH_DIRTY_SET, $key)
                  ->incrBy(self::SEARCH_KEY . $key, 1)
                  ->exec();

            return true;
        } catch (\RedisException $e) {
            $this->unavailable = true;
            return false;
        }
    }

    /**
     * Drena a série diária de buscas.
     *
     * @param callable(string $dia, array $dims, int $buscas): bool $apply
     */
    public function flushSearches(callable $apply): int
    {
        $redis = $this->redis();
        if ($redis === null) {
            return 0;
        }

        $flushed = 0;

        foreach ($redis->sMembers(self::SEARCH_DIRTY_SET) ?: [] as $key) {
            $buscas = (int) $redis->getDel(self::SEARCH_KEY . $key);

            if ($buscas <= 0) {
                $redis->sRem(self::SEARCH_DIRTY_SET, $key);
                continue;
            }

            $parts = explode('|', $key, 6);
            $dia   = array_shift($parts);
            $dims  = $parts;

            $ok = false;
            try {
                $ok = $apply($dia, $dims, $buscas);
            } catch (\Throwable $e) {
                log_message('error', "[RedisMetricsBuffer] Falha ao aplicar busca {$key}: " . $e->getMessage());
            }

            if ($ok) {
                $redis->sRem(self::SEARCH_DIRTY_SET, $key);
                $flushed++;
            } else {
                $redis->incrBy(self::SEARCH_KEY . $key, $buscas);
            }
        }

        return $flushed;
    }
}
