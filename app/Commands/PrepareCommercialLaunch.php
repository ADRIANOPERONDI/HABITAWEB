<?php

namespace App\Commands;

use App\Models\PlanLaunchRampModel;
use App\Models\PlanModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;
use Config\Database;

/**
 * Prepara, num comando só, os dados que precisam existir no banco antes de
 * ligar o modelo comercial novo (Prata/Ouro/Diamante + rampa + cobrança por
 * lead) — hoje isso exigia três passos manuais (`db:seed MainSeeder` e um
 * `UPDATE` direto em `plan_launch_ramps`, documentados em
 * `GUIA_ESCALABILIDADE_PRODUCAO.md` §13.1). Junta os dois num comando
 * idempotente, seguro de rodar mais de uma vez.
 *
 * Não é a virada em si — os seeders continuam respeitando os próprios
 * gatilhos de segurança (`LeadChargeRuleSeeder` só cobra a partir de
 * `LEAD_CHARGE_VALID_FROM`; a rampa só vale pra assinatura com
 * `ramp_started_at` preenchido). Rodar este comando não muda o que nenhuma
 * conta já paga; só deixa o catálogo/regras prontos pra quando a virada
 * (comunicação ao cliente + `planos:migrar-comercial`) acontecer.
 *
 * `--rampa-valid-to` tem o valor combinado com o cliente (decisão registrada
 * em `fix/decisoes-comerciais-cliente`): a promoção da rampa vale pra quem
 * ENTRAR até essa data — não encurta a rampa de quem já aderiu antes dela
 * (ver `LaunchRampService::percentFor()`).
 */
class PrepareCommercialLaunch extends BaseCommand
{
    protected $group       = 'Assinaturas';
    protected $name        = 'comercial:preparar-lancamento';
    protected $description = 'Semeia planos, pacotes e regras de cobranca por lead, e aplica a janela de validade da rampa — tudo num comando so, idempotente.';
    protected $usage       = 'comercial:preparar-lancamento [--rampa-valid-from YYYY-MM-DD] [--rampa-valid-to YYYY-MM-DD] [--dry-run]';
    protected $options     = [
        '--rampa-valid-from' => 'Data em que a promocao da rampa comeca a valer para novas adesoes. Default: hoje.',
        '--rampa-valid-to'   => 'Data limite para ENTRAR na rampa (nao encurta quem ja aderiu). Default: 2026-12-31, combinado com o cliente.',
        '--dry-run'          => 'Roda os seeders normalmente (idempotentes, baixo risco) mas nao grava a janela da rampa — so mostra o que seria aplicado.',
    ];

    public function run(array $params)
    {
        $dryRun    = CLI::getOption('dry-run') !== null || array_key_exists('dry-run', $params);
        $validFrom = CLI::getOption('rampa-valid-from') ?? ($params['rampa-valid-from'] ?? date('Y-m-d'));
        $validTo   = CLI::getOption('rampa-valid-to') ?? ($params['rampa-valid-to'] ?? '2026-12-31');

        if (strtotime((string) $validFrom) === false || strtotime((string) $validTo) === false) {
            CLI::error('Datas invalidas. Use o formato YYYY-MM-DD.');

            return EXIT_ERROR;
        }

        if ($validTo < $validFrom) {
            CLI::error("--rampa-valid-to ({$validTo}) nao pode ser anterior a --rampa-valid-from ({$validFrom}).");

            return EXIT_ERROR;
        }

        CLI::write('Preparando o modelo comercial de lancamento...', 'yellow');

        $resumo = [];

        $seeder = new Seeder(new Database());
        foreach (['PlanSeeder', 'PromotionPackageSeeder', 'LeadChargeRuleSeeder'] as $nomeSeeder) {
            $seeder->call($nomeSeeder);
            $resumo[] = [$nomeSeeder, 'ok'];
            CLI::write("  {$nomeSeeder} rodado.", 'green');
        }

        $planos = model(PlanModel::class)->where('ativo', true)->countAllResults();

        if ($dryRun) {
            CLI::write("  [dry-run] janela da rampa NAO aplicada — seria valid_from={$validFrom} valid_to={$validTo}.", 'yellow');
            $resumo[] = ['plan_launch_ramps (janela)', "[dry-run] valid_from={$validFrom} valid_to={$validTo}"];
        } else {
            // where('id >', 0) sempre verdadeiro pras 3 faixas — CI4 recusa
            // update() sem WHERE nenhum ("Updates are not allowed unless...").
            model(PlanLaunchRampModel::class)
                ->where('id >', 0)
                ->set(['valid_from' => $validFrom, 'valid_to' => $validTo])
                ->update();
            CLI::write("  plan_launch_ramps: janela aplicada (valid_from={$validFrom}, valid_to={$validTo}).", 'green');
            $resumo[] = ['plan_launch_ramps (janela)', "valid_from={$validFrom} valid_to={$validTo}"];
        }

        CLI::newLine();
        CLI::table($resumo, ['Etapa', 'Resultado']);
        CLI::write("Planos ativos no catalogo: {$planos}.", 'green');
        CLI::write('Concluido. Isto NAO liga cobranca em nenhuma conta existente — ver GUIA_ESCALABILIDADE_PRODUCAO.md secao 13 para o runbook da virada.', 'yellow');

        return EXIT_SUCCESS;
    }
}
