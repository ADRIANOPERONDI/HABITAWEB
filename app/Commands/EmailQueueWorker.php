<?php

namespace App\Commands;

use App\Libraries\Queue\RedisEmailQueue;
use App\Services\NotificationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Worker da fila de e-mail (hw:queue:email) — consome via BLPOP e envia com
 * NotificationService::sendEmail(immediate: true). Job que falha volta pra
 * fila até RedisEmailQueue::MAX_ATTEMPTS; depois vai para a lista de falhas.
 *
 * Dois modos de operação (documentados no GUIA_ESCALABILIDADE_PRODUCAO.md):
 *
 * 1. systemd (recomendado — entrega quase em tempo real):
 *      ExecStart=/usr/bin/php spark email:work
 *      Restart=always
 *    (sem --max-time, roda pra sempre; systemd reinicia se cair)
 *
 * 2. cron a cada minuto (mais simples, latência de até ~1min):
 *      * * * * * cd /var/www/habitaweb && php spark email:work --max-time 55
 *
 * Rodar numa instância só, como os demais workers/crons.
 */
class EmailQueueWorker extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'email:work';
    protected $description = 'Consome a fila de e-mail em Redis e envia via SMTP.';
    protected $options     = [
        '--max-time' => 'Encerra após N segundos (para uso via cron). 0 = roda para sempre (systemd).',
        '--max-jobs' => 'Encerra após N jobs processados. 0 = sem limite.',
    ];

    public function run(array $params)
    {
        $maxTime = (int) (CLI::getOption('max-time') ?? 0);
        $maxJobs = (int) (CLI::getOption('max-jobs') ?? 0);

        $queue   = new RedisEmailQueue();
        $service = new NotificationService();

        if ($queue->size() === null) {
            CLI::error('Redis indisponível — nada a consumir (os envios estão caindo no modo síncrono).');
            return;
        }

        CLI::write('Worker de e-mail iniciado (fila: ' . RedisEmailQueue::QUEUE_KEY . ', pendentes: ' . $queue->size() . ')', 'green');

        $startedAt = time();
        $processed = 0;

        while (true) {
            if ($maxTime > 0 && (time() - $startedAt) >= $maxTime) {
                break;
            }
            if ($maxJobs > 0 && $processed >= $maxJobs) {
                break;
            }

            $job = $queue->pop(5);
            if ($job === null) {
                continue; // timeout do BLPOP — volta pro loop (checa max-time)
            }

            $attempts = (int) ($job['attempts'] ?? 0) + 1;
            $ok = false;

            try {
                $ok = $service->sendEmail(
                    (string) $job['to'],
                    (string) $job['subject'],
                    (string) $job['message'],
                    immediate: true
                );
            } catch (\Throwable $e) {
                log_message('error', '[EmailQueueWorker] Exceção ao enviar para ' . ($job['to'] ?? '?') . ': ' . $e->getMessage());
            }

            $processed++;

            if ($ok) {
                CLI::write("  enviado: {$job['to']} ({$job['subject']})", 'green');
            } elseif ($attempts < RedisEmailQueue::MAX_ATTEMPTS) {
                $queue->push((string) $job['to'], (string) $job['subject'], (string) $job['message'], $attempts);
                CLI::write("  falhou (tentativa {$attempts}/" . RedisEmailQueue::MAX_ATTEMPTS . "), reenfileirado: {$job['to']}", 'yellow');
            } else {
                $job['attempts'] = $attempts;
                $queue->pushFailed($job);
                CLI::error("  esgotou tentativas, movido para " . RedisEmailQueue::FAILED_KEY . ": {$job['to']}");
            }
        }

        CLI::write("Worker encerrado. Jobs processados: {$processed}.", 'cyan');
    }
}
