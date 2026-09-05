<?php

namespace App\Commands;

use App\Services\LeadChargeService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Aprovação automática das cobranças de lead cujo prazo de contestação
 * (7 dias) já passou sem o tenant contestar. Cron diário — sem isto,
 * `markInvoiced()` nunca teria PENDING nenhuma vencida para promover, e o
 * fechamento de ciclo (`leads:fechar-ciclo`) nunca encontraria APPROVED
 * para faturar.
 */
class LeadsApproveCharges extends BaseCommand
{
    protected $group       = 'Leads';
    protected $name        = 'leads:aprovar-cobrancas';
    protected $description = 'Aprova automaticamente cobrancas de lead cujo prazo de contestacao ja passou.';

    public function run(array $params)
    {
        CLI::write('Aprovando cobrancas de lead vencidas...', 'yellow');

        try {
            $n = (new LeadChargeService())->approveExpired();

            CLI::write("Concluido: {$n} cobranca(s) aprovada(s).", 'green');
        } catch (\Throwable $e) {
            CLI::error('Erro ao aprovar cobrancas: ' . $e->getMessage());
        }
    }
}
