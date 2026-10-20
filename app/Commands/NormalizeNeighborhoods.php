<?php

namespace App\Commands;

use App\Libraries\Text\NeighborhoodName;
use App\Services\NeighborhoodResolver;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Unifica as grafias de bairro já gravadas ("CENTRO" / "Centro" / "centro ").
 *
 * A gravação nova já passa por NeighborhoodResolver; este comando arruma o que
 * veio antes e serve de reprocesso depois de cadastrar aliases. Agrupa sempre
 * por cidade + bairro (sem caixa/acento/espaço extra) e nunca funde nomes de
 * chave diferente — "Centro" e "Centro Histórico" só se juntam via alias.
 *
 * Uso:
 *   php spark imoveis:normalizar-bairros --dry-run   # só relata os grupos
 *   php spark imoveis:normalizar-bairros             # pede confirmação e grava
 *   php spark imoveis:normalizar-bairros --force     # grava sem perguntar
 *   php spark imoveis:normalizar-bairros --suspeitos # lista bairros que parecem ponto de referência
 */
class NormalizeNeighborhoods extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'imoveis:normalizar-bairros';
    protected $description = 'Unifica grafias de bairro (caixa, acento, espaços) dentro de cada cidade.';
    protected $options     = [
        '--dry-run'   => 'Só relata o que seria alterado, sem gravar nada.',
        '--force'     => 'Não pede confirmação antes de gravar.',
        '--suspeitos' => 'Lista os bairros que parecem ponto de referência ("Próximo a ...") e sai.',
    ];

    public function run(array $params)
    {
        if (CLI::getOption('suspeitos') !== null) {
            return $this->listarSuspeitos();
        }

        $resolver = new NeighborhoodResolver();
        $grupos   = $resolver->backfill(true);

        if ($grupos === []) {
            CLI::write('Nenhum bairro com grafia divergente.', 'green');

            return;
        }

        foreach ($grupos as $g) {
            CLI::write(sprintf(
                '%s: [%s] -> "%s" (%d imóveis)',
                $g['cidade'],
                implode(' | ', $g['variantes']),
                $g['canonico'],
                $g['imoveis']
            ));
        }

        if (CLI::getOption('dry-run') !== null) {
            CLI::write(count($grupos) . ' grupo(s) seriam unificados (dry-run, nada gravado).', 'yellow');

            return;
        }

        if (CLI::getOption('force') === null && CLI::prompt('Gravar estas alterações?', ['n', 'y']) !== 'y') {
            CLI::write('Cancelado.', 'yellow');

            return;
        }

        $resolver->backfill(false);
        CLI::write(count($grupos) . ' grupo(s) unificados.', 'green');
    }

    private function listarSuspeitos(): void
    {
        $linhas = db_connect()->table('properties')
            ->select('cidade, bairro, COUNT(*) AS total')
            ->groupBy('cidade, bairro')
            ->get()->getResultArray();

        $achou = false;
        foreach ($linhas as $l) {
            if (NeighborhoodName::looksLikeReference($l['bairro'])) {
                $achou = true;
                CLI::write(sprintf('%s | "%s" (%d imóveis)', $l['cidade'], $l['bairro'], $l['total']), 'yellow');
            }
        }

        if (! $achou) {
            CLI::write('Nenhum bairro suspeito.', 'green');
        }
    }
}
