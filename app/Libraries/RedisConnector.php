<?php

namespace App\Libraries;

/**
 * Fábrica de conexões \Redis cruas para infraestrutura própria (buffer de
 * métricas, fila de e-mail) — lê a MESMA config do cache (cache.redis.* no
 * .env), mas as conexões são independentes do cache handler do CI4 (que não
 * expõe enumeração/listas/BLPOP).
 *
 * Retorna null em qualquer falha — os chamadores são todos fail-open
 * (degradam para o comportamento síncrono anterior).
 */
class RedisConnector
{
    public static function make(): ?\Redis
    {
        if (! extension_loaded('redis')) {
            return null;
        }

        $config = config('Cache')->redis;

        try {
            $redis = new \Redis();
            if (! $redis->connect($config['host'], (int) ($config['port'] ?? 6379), (float) ($config['timeout'] ?? 1))) {
                return null;
            }
            if (! empty($config['password']) && ! $redis->auth($config['password'])) {
                return null;
            }
            if (isset($config['database']) && ! $redis->select((int) $config['database'])) {
                return null;
            }

            return $redis;
        } catch (\RedisException $e) {
            log_message('warning', '[RedisConnector] Redis indisponível: ' . $e->getMessage());
            return null;
        }
    }
}
