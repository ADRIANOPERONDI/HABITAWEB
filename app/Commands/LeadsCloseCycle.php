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
        $periodoExplicito = CLI::getOption('periodo');
        $dryRun           = CLI::getOption('dry-run') !== null;
        $chargeModel      = model(LeadChargeModel::class);

        if ($periodoExplicito !== null) {
            $pares = array_map(
                static fn (int $accountId) => ['account_id' => $accountId, 'periodo' => $periodoExplicito],
                $chargeModel->accountsWithApprovedForPeriod($periodoExplicito)
            );
            $rotulo = 'periodo ' . $periodoExplicito;
        } else {
            // Sem --periodo: fecha TODO período atrasado (mês passado e
            // qualquer mês mais antigo que tenha ficado pra trás) — uma
            // aprovação tardia ou um cron que não rodou não pode deixar
            // cobrança presa pra sempre, esperando alguém lembrar de rodar
            // com --periodo na mão.
            $pares  = $chargeModel->approvedAccountPeriods(date('Y-m-01'));
            $rotulo = 'todos os periodos atrasados';
        }

        CLI::write('Fechando ciclo de cobranca de lead — ' . $rotulo . ($dryRun ? ' (dry-run)' : ''), 'yellow');

        if ($pares === []) {
            CLI::write('Nenhuma cobranca aprovada pendente. Nada a fazer.', 'green');

            return;
        }

        if ($dryRun) {
            $this->printDryRun($chargeModel, $pares);

            return;
        }

        $service = new LeadChargeService();
        $resumo  = ['invoiced_free' => 0, 'invoiced_charged' => 0, 'gateway_indisponivel' => 0, 'nothing' => 0];

        foreach ($pares as $par) {
            try {
                $resultado = $service->closeCycleForAccount($par['account_id'], $par['periodo']);
                $resumo[$resultado['status']] = ($resumo[$resultado['status']] ?? 0) + 1;

                CLI::write(sprintf(
                    '  conta %d (%s): %s — total R$ %.2f, credito R$ %.2f, cobrado R$ %.2f',
                    $par['account_id'],
                    $par['periodo'],
                    $resultado['status'],
                    $resultado['total'],
                    $resultado['credit_applied'],
                    $resultado['charged']
                ));
            } catch (\Throwable $e) {
                CLI::error("  conta {$par['account_id']} ({$par['periodo']}): erro — " . $e->getMessage());
            }
        }

        CLI::write('Concluido: ' . json_encode($resumo), 'green');
    }

    private function printDryRun(LeadChargeModel $chargeModel, array $pares): void
    {
        $creditModel = model(\App\Models\LeadCreditLedgerModel::class);

        CLI::table(
            array_map(static function (array $par) use ($chargeModel, $creditModel) {
                $charges = $chargeModel->approvedForPeriod($par['account_id'], $par['periodo']);
                $total   = array_sum(array_map(static fn ($c) => (float) $c->commission_value, $charges));
                $saldo   = $creditModel->balanceFor($par['account_id'], $par['periodo']);

                return [
                    $par['account_id'],
                    $par['periodo'],
                    count($charges),
                    number_format($total, 2, ',', '.'),
                    number_format($saldo, 2, ',', '.'),
                    number_format(max(0, $total - $saldo), 2, ',', '.'),
                ];
            }, $pares),
            ['Conta', 'Periodo', 'Cobrancas', 'Total', 'Credito disponivel', 'A cobrar no gateway']
        );
    }
}
