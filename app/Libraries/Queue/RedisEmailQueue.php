<?php

namespace App\Libraries\Queue;

use App\Libraries\RedisConnector;

/**
 * Fila de e-mail em Redis (lista + BLPOP) — Fase 4.2 do plano de
 * escalabilidade. Tira o handshake SMTP (potencialmente segundos num host
 * lento) da thread da requisição: NotificationService::sendEmail enfileira e
 * o worker (spark email:work) consome quase em tempo real.
 *
 * Fail-open como toda a infraestrutura Redis própria: qualquer indisponível
 * => push() retorna false e o chamador envia síncrono (comportamento antigo).
 *
 * Jobs que falham no envio voltam pra fila até MAX_ATTEMPTS; depois vão para
 * a lista de falhas (hw:queue:email:failed) para inspeção manual
 * (redis-cli LRANGE hw:queue:email:failed 0 -1).
 */
class RedisEmailQueue
{
    public const QUEUE_KEY  = 'hw:queue:email';
    public const FAILED_KEY = 'hw:queue:email:failed';
    public const MAX_ATTEMPTS = 3;

    private ?\Redis $redis = null;
    private bool $unavailable = false;

    private function redis(): ?\Redis
    {
        if ($this->unavailable) {
            return null;
        }
        if ($this->redis !== null) {
            return $this->redis;
        }

        $redis = RedisConnector::make();
        if ($redis === null) {
            $this->unavailable = true;
            return null;
        }

        return $this->redis = $redis;
    }

    /**
     * Enfileira um e-mail. false => chamador deve enviar síncrono.
     */
    public function push(string $to, string $subject, string $message, int $attempts = 0): bool
    {
        $redis = $this->redis();
        if ($redis === null) {
            return false;
        }

        $job = json_encode([
            'to'        => $to,
            'subject'   => $subject,
            'message'   => $message,
            'attempts'  => $attempts,
            'queued_at' => date('c'),
        ]);

        try {
            return $redis->rPush(self::QUEUE_KEY, $job) !== false;
        } catch (\RedisException $e) {
            $this->unavailable = true;
            return false;
        }
    }

    /**
     * Retira o próximo job (bloqueia até $timeoutSeconds). null = fila vazia
     * no intervalo, ou Redis indisponível.
     */
    public function pop(int $timeoutSeconds = 5): ?array
    {
        $redis = $this->redis();
        if ($redis === null) {
            return null;
        }

        try {
            $item = $redis->blPop([self::QUEUE_KEY], $timeoutSeconds);
        } catch (\RedisException $e) {
            $this->unavailable = true;
            return null;
        }

        if (empty($item[1])) {
            return null;
        }

        $job = json_decode($item[1], true);

        return is_array($job) ? $job : null;
    }

    /** Job esgotou as tentativas: vai para a lista de falhas (inspeção manual). */
    public function pushFailed(array $job): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            log_message('error', '[RedisEmailQueue] Job de e-mail perdido (Redis fora e tentativas esgotadas): ' . json_encode($job));
            return;
        }

        try {
            $job['failed_at'] = date('c');
            $redis->rPush(self::FAILED_KEY, json_encode($job));
        } catch (\RedisException $e) {
            log_message('error', '[RedisEmailQueue] Falha ao registrar job na lista de falhas: ' . json_encode($job));
        }
    }

    /** Tamanho atual da fila (null = Redis indisponível). */
    public function size(): ?int
    {
        $redis = $this->redis();
        if ($redis === null) {
            return null;
        }

        try {
            $len = $redis->lLen(self::QUEUE_KEY);
            return $len === false ? null : (int) $len;
        } catch (\RedisException $e) {
            return null;
        }
    }
}
