<?php

namespace Tests\Feature;

use App\Libraries\Queue\RedisEmailQueue;
use App\Services\NotificationService;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre a fila de e-mail em Redis (Fase 4.2): round-trip push/pop com
 * preservação do payload, contagem de tentativas, lista de falhas, e o
 * comportamento do NotificationService com SMTP não configurado (retorna
 * false SEM enfileirar — enfileirar e-mail que nunca poderá sair só
 * esconderia o problema de configuração).
 *
 * Roda contra o Redis real do ambiente de teste (cache.redis.* do
 * .env.testing, DB isolado do dev).
 */
final class EmailQueueTest extends HabitawebTestCase
{
    private RedisEmailQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new RedisEmailQueue();

        if ($this->queue->size() === null) {
            $this->markTestSkipped('Redis indisponível no ambiente de teste.');
        }

        $this->drainQueues();
    }

    protected function tearDown(): void
    {
        $this->drainQueues();
        parent::tearDown();
    }

    private function drainQueues(): void
    {
        $redis = \App\Libraries\RedisConnector::make();
        if ($redis !== null) {
            $redis->del(RedisEmailQueue::QUEUE_KEY, RedisEmailQueue::FAILED_KEY);
        }
    }

    public function testPushPopRoundTripPreservesPayloadAndAttempts(): void
    {
        $this->assertTrue($this->queue->push('dest@exemplo.com', 'Assunto á', '<b>corpo</b>', attempts: 2));
        $this->assertSame(1, $this->queue->size());

        $job = $this->queue->pop(1);

        $this->assertNotNull($job);
        $this->assertSame('dest@exemplo.com', $job['to']);
        $this->assertSame('Assunto á', $job['subject']);
        $this->assertSame('<b>corpo</b>', $job['message']);
        $this->assertSame(2, $job['attempts']);
        $this->assertSame(0, $this->queue->size());

        // Fila vazia: pop com timeout curto volta null, sem erro.
        $this->assertNull($this->queue->pop(1));
    }

    public function testFailedJobsLandInFailedList(): void
    {
        $this->queue->pushFailed(['to' => 'x@y.z', 'subject' => 's', 'message' => 'm', 'attempts' => 3]);

        $redis = \App\Libraries\RedisConnector::make();
        $this->assertSame(1, (int) $redis->lLen(RedisEmailQueue::FAILED_KEY));

        $failed = json_decode($redis->lIndex(RedisEmailQueue::FAILED_KEY, 0), true);
        $this->assertSame('x@y.z', $failed['to']);
        $this->assertArrayHasKey('failed_at', $failed);
    }

    public function testUnconfiguredSmtpReturnsFalseWithoutQueueing(): void
    {
        // Ambiente de teste não tem mail.host/mail.user configurados no banco
        // — o guard de "SMTP não configurado" deve responder false ANTES de
        // tocar na fila.
        $result = (new NotificationService())->sendEmail('dest@exemplo.com', 'Teste', 'corpo');

        $this->assertFalse($result);
        $this->assertSame(0, $this->queue->size());
    }
}
