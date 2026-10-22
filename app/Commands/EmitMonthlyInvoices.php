<?php

namespace App\Commands;

use App\Services\MonthlyInvoiceService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Fatura única mensal (cron dia 1): uma cobrança por conta com a mensalidade
 * do mês e os leads aprovados dos meses anteriores, vencendo dia 10 (ou o
 * próximo dia útil), paga por PIX ou boleto. Ver MonthlyInvoiceService.
 *
 * Roda depois de `creditos:conceder` (00:05) e `leads:aprovar-cobrancas`
 * (03:00) do mesmo dia. Idempotente: rodar de novo não cobra duas vezes.
 *
 * Uso:
 *   php spark cobrancas:emitir-faturas                          # competência = mês atual
 *   php spark cobrancas:emitir-faturas --competencia 2026-11-01
 *   php spark cobrancas:emitir-faturas --dry-run                # só mostra
 */
class EmitMonthlyInvoices extends BaseCommand
{
    protected $group       = 'Cobrança';
    protected $name        = 'cobrancas:emitir-faturas';
    protected $description = 'Emite a fatura unica do mes (mensalidade + leads) de cada conta, vencendo dia 10.';
    protected $usage       = 'cobrancas:emitir-faturas [--competencia AAAA-MM-01] [--dry-run]';
    protected $options     = [
        '--competencia' => 'Mes da fatura (default: mes atual).',
        '--dry-run'     => 'So mostra o que seria cobrado, sem gravar nem cobrar.',
    ];

    public function run(array $params)
    {
        $competencia = (string) (CLI::getOption('competencia') ?? ($params['competencia'] ?? date('Y-m-01')));
        if (! preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $competencia)) {
            CLI::error('Competencia invalida. Use AAAA-MM-01.');

            return EXIT_ERROR;
        }
        $competencia = substr($competencia, 0, 7) . '-01';
        $dryRun      = CLI::getOption('dry-run') !== null || array_key_exists('dry-run', $params);
        $service     = new MonthlyInvoiceService();

        CLI::write('Fatura unica de ' . MonthlyInvoiceService::mesPorExtenso($competencia) . ' — vencimento ' . date('d/m/Y', strtotime(MonthlyInvoiceService::vencimento($competencia))) . ($dryRun ? ' (dry-run)' : ''), 'yellow');

        $linhas = [];
        $resumo = [];
        foreach ($service->contasParaFaturar($competencia) as $accountId) {
            try {
                if ($dryRun) {
                    $p = $service->preview($accountId, $competencia);
                    if (! $p['mensalidade'] && $p['leads']['quantidade'] === 0) {
                        continue;
                    }
                    $linhas[] = [$accountId, $p['mensalidade'] ? number_format($p['mensalidade']['valor'], 2, ',', '.') : '-', $p['leads']['quantidade'], number_format($p['leads']['total'], 2, ',', '.') . ' (antes do credito)'];

                    continue;
                }

                $r = $service->emitir($accountId, $competencia);
                $resumo[$r['status']] = ($resumo[$r['status']] ?? 0) + 1;
                if ($r['status'] !== 'nada') {
                    $linhas[] = [$accountId, $r['status'], number_format($r['total'], 2, ',', '.'), $r['payment_transaction_id'] ?? '-'];
                }
            } catch (\Throwable $e) {
                $resumo['erro'] = ($resumo['erro'] ?? 0) + 1;
                CLI::error("  conta {$accountId}: erro — " . $e->getMessage());
            }
        }

        if ($linhas !== []) {
            CLI::table($linhas, $dryRun ? ['Conta', 'Mensalidade', 'Leads', 'Valor leads'] : ['Conta', 'Situacao', 'Total', 'Transacao']);
        } else {
            CLI::write('Nada a faturar.', 'green');
        }

        if (! $dryRun) {
            CLI::write('Concluido: ' . json_encode($resumo), 'green');
        }

        return EXIT_SUCCESS;
    }
}
