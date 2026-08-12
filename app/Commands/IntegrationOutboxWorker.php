<?php

namespace App\Commands;

use App\Services\IntegrationOutboxService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Entrega os itens pendentes da fila de saída das integrações (leads → CRM).
 *
 * Diferente do EmailQueueWorker, que fica bloqueado num BLPOP do Redis, este
 * consulta uma tabela: o volume é baixo (um item por lead) e a durabilidade
 * importa mais que a latência — um lead não pode sumir num restart.
 *
 * Cron sugerido, na mesma instância worker dos demais:
 *   * * * * * cd /var/www/habitaweb && php spark integration:outbox --max-time=55
 */
class IntegrationOutboxWorker extends BaseCommand
{
    protected $group       = 'Integracoes';
    protected $name        = 'integration:outbox';
    protected $description = 'Entrega os leads pendentes para o CRM das plataformas integradas.';
    protected $usage       = 'integration:outbox [options]';
    protected $options     = [
        '--max-time' => 'Segundos até encerrar (padrão: 55, para caber num cron de minuto).',
        '--limit'    => 'Máximo de itens por ciclo (padrão: 50).',
        '--once'     => 'Roda um único ciclo e sai.',
    ];

    public function run(array $params)
    {
        $maxTime = (int) (CLI::getOption('max-time') ?: 55);
        $limit   = (int) (CLI::getOption('limit') ?: 50);
        $once    = (bool) CLI::getOption('once');

        $service = new IntegrationOutboxService();
        $deadline = time() + max(1, $maxTime);
        $total    = ['processed' => 0, 'sent' => 0, 'retried' => 0, 'failed' => 0];

        do {
            $stats = $service->processDue($limit);

            foreach ($stats as $k => $v) {
                $total[$k] += $v;
            }

            // Fila vazia: não adianta martelar o banco até o deadline.
            if ($stats['processed'] === 0) {
                break;
            }

            if ($once) {
                break;
            }
        } while (time() < $deadline);

        if ($total['processed'] === 0) {
            CLI::write('Nada pendente na fila.', 'dark_gray');

            return EXIT_SUCCESS;
        }

        CLI::write(sprintf(
            '%d item(ns): %d enviado(s), %d reagendado(s), %d desistido(s).',
            $total['processed'],
            $total['sent'],
            $total['retried'],
            $total['failed'],
        ), $total['failed'] > 0 ? 'yellow' : 'green');

        return EXIT_SUCCESS;
    }
}
