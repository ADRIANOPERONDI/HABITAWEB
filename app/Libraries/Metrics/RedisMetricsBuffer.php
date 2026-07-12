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
}
