<?php

namespace App\Commands;

use App\Services\LeadCreditService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Concessão do crédito mensal de lead (Ouro/Diamante) — dia 1 de cada mês,
 * antes de `leads:fechar-ciclo` fechar o ciclo anterior.
 *
 * Idempotente: rodar duas vezes no mesmo mês não duplica a concessão (índice
 * único parcial em lead_credit_ledger).
 */
class CreditosConceder extends BaseCommand
{
    protected $group       = 'Leads';
    protected $name        = 'creditos:conceder';
    protected $description = 'Concede o credito mensal de lead as contas com plano elegivel.';

    public function run(array $params)
    {
        $periodo = $params[0] ?? date('Y-m-01');

        CLI::write("Concedendo credito mensal de lead para o periodo {$periodo}...", 'yellow');

        try {
            $n = (new LeadCreditService())->grantMonthly($periodo);

            CLI::write("Concluido: {$n} conta(s) receberam credito.", 'green');
        } catch (\Throwable $e) {
            CLI::error('Erro ao conceder credito: ' . $e->getMessage());
        }
    }
}
