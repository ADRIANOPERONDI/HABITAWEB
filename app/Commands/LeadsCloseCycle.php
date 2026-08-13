<?php

namespace App\Commands;

use App\Models\LeadChargeModel;
use App\Services\LeadChargeService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Fecha o ciclo de faturamento de lead de um período: soma as APPROVED por
 * conta, abate o crédito do mês, cobra o restante no gateway e marca tudo
 * INVOICED. Roda dia 1, depois de `creditos:conceder` ter concedido o
 * crédito do novo mês (o crédito consumido aqui é do MÊS QUE FECHOU, já
 * concedido no dia 1 do mês anterior).
 *
 * `--periodo=AAAA-MM-01` sobrescreve o período (default: o mês anterior ao
 * atual). `--dry-run` só lista o que fecharia, sem gravar nada.
 */
class LeadsCloseCycle extends BaseCommand
{
    protected $group       = 'Leads';
    protected $name        = 'leads:fechar-ciclo';
    protected $description = 'Fecha o ciclo de cobranca de lead de um periodo: credito, cobranca no gateway e fatura.';
    protected $usage       = 'leads:fechar-ciclo [--periodo AAAA-MM-01] [--dry-run]';
    protected $options     = [
        '--periodo' => 'Mes de competencia a fechar (default: mes anterior).',
        '--dry-run' => 'Nao grava nada, so lista o que fecharia.',
    ];

    public function run(array $params)
    {
        $periodo = CLI::getOption('periodo') ?: date('Y-m-01', strtotime('first day of last month'));
        $dryRun  = CLI::getOption('dry-run') !== null;

        CLI::write('Fechando ciclo de cobranca de lead — periodo ' . $periodo . ($dryRun ? ' (dry-run)' : ''), 'yellow');

        $chargeModel = model(LeadChargeModel::class);
        $accountIds  = $chargeModel->accountsWithApprovedForPeriod($periodo);

        if ($accountIds === []) {
            CLI::write('Nenhuma cobranca aprovada nesse periodo. Nada a fazer.', 'green');

            return;
        }

        if ($dryRun) {
            $this->printDryRun($chargeModel, $accountIds, $periodo);

            return;
        }

        $service = new LeadChargeService();
        $resumo  = ['invoiced_free' => 0, 'invoiced_charged' => 0, 'gateway_indisponivel' => 0, 'nothing' => 0];

        foreach ($accountIds as $accountId) {
            try {
                $resultado = $service->closeCycleForAccount($accountId, $periodo);
                $resumo[$resultado['status']] = ($resumo[$resultado['status']] ?? 0) + 1;

                CLI::write(sprintf(
                    '  conta %d: %s — total R$ %.2f, credito R$ %.2f, cobrado R$ %.2f',
                    $accountId,
                    $resultado['status'],
                    $resultado['total'],
                    $resultado['credit_applied'],
                    $resultado['charged']
                ));
            } catch (\Throwable $e) {
                CLI::error("  conta {$accountId}: erro — " . $e->getMessage());
            }
        }

        CLI::write('Concluido: ' . json_encode($resumo), 'green');
    }

    private function printDryRun(LeadChargeModel $chargeModel, array $accountIds, string $periodo): void
    {
        $creditModel = model(\App\Models\LeadCreditLedgerModel::class);

        CLI::table(
            array_map(static function (int $accountId) use ($chargeModel, $creditModel, $periodo) {
                $charges = $chargeModel->approvedForPeriod($accountId, $periodo);
                $total   = array_sum(array_map(static fn ($c) => (float) $c->commission_value, $charges));
                $saldo   = $creditModel->balanceFor($accountId, $periodo);

                return [
                    $accountId,
                    count($charges),
                    number_format($total, 2, ',', '.'),
                    number_format($saldo, 2, ',', '.'),
                    number_format(max(0, $total - $saldo), 2, ',', '.'),
                ];
            }, $accountIds),
            ['Conta', 'Cobrancas', 'Total', 'Credito disponivel', 'A cobrar no gateway']
        );
    }
}
