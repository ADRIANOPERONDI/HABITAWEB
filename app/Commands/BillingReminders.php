<?php

namespace App\Commands;

use App\Services\BillingReminderService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Avisos de vencimento por e-mail (cron diário): 3 dias antes, no dia, 1 dia
 * de atraso, véspera da suspensão e suspensão. Cada fatura recebe cada aviso
 * uma vez só (ver BillingReminderService).
 *
 * Uso:
 *   php spark cobrancas:avisar-vencimento            # envia
 *   php spark cobrancas:avisar-vencimento --dry-run  # só lista o que enviaria
 */
class BillingReminders extends BaseCommand
{
    protected $group       = 'Cobrança';
    protected $name        = 'cobrancas:avisar-vencimento';
    protected $description = 'Envia por e-mail os avisos de vencimento, atraso e suspensão das faturas em aberto.';
    protected $usage       = 'cobrancas:avisar-vencimento [--dry-run]';
    protected $options     = ['--dry-run' => 'Só lista os avisos que seriam enviados.'];

    public function run(array $params)
    {
        $dryRun = CLI::getOption('dry-run') !== null || array_key_exists('dry-run', $params);
        $avisos = (new BillingReminderService())->run(null, $dryRun);

        if ($avisos === []) {
            CLI::write('Nenhum aviso a enviar hoje.', 'green');

            return EXIT_SUCCESS;
        }

        CLI::table(
            array_map(static fn (array $a) => [$a['account_id'], $a['transaction_id'], $a['etapa'], $dryRun ? '(dry-run)' : ($a['email'] ? 'enviado' : 'falhou/sem e-mail')], $avisos),
            ['Conta', 'Fatura', 'Aviso', 'E-mail']
        );

        return EXIT_SUCCESS;
    }
}
