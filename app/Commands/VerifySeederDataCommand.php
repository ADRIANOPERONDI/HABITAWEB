<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\PlanModel;
use App\Models\PromotionPackageModel;

class VerifySeederDataCommand extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'db:verify-seeders';
    protected $description = 'Verify if plans and promotion packages were seeded correctly';
    protected $usage       = 'php spark db:verify-seeders';

    public function run(array $params = [])
    {
        $planModel = new PlanModel();
        $packageModel = new PromotionPackageModel();

        CLI::write('', 'white');
        CLI::write('╔════════════════════════════════════════════════════════════════╗', 'cyan');
        CLI::write('║           VERIFICAÇÃO DE PLANOS E PACOTES DE TURBINAR         ║', 'cyan');
        CLI::write('╚════════════════════════════════════════════════════════════════╝', 'cyan');
        CLI::write('', 'white');

        // 1. Verificar Planos
        CLI::write('📋 PLANOS CRIADOS:', 'green');
        CLI::write('─────────────────────────────────────────────────────────────────', 'yellow');

        $plans = $planModel->withDeleted(false)->findAll();

        if (empty($plans)) {
            CLI::write('❌ Nenhum plano encontrado!', 'red');
        } else {
            foreach ($plans as $idx => $plan) {
                CLI::write("", 'white');
                CLI::write(sprintf(
                    "%d. %s (%s)",
                    $idx + 1,
                    $plan->nome,
                    $plan->chave
                ), 'white');
                CLI::write(sprintf(
                    "   💰 Mensal: R$ %.2f | Anual: R$ %.2f",
                    $plan->preco_mensal,
                    $plan->preco_anual
                ), 'white');
                CLI::write(sprintf(
                    "   📦 Anúncios: %s | ⭐ Destaques: %d",
                    $plan->limite_imoveis_ativos ?? 'Ilimitado',
                    $plan->destaques_mensais ?? 0
                ), 'white');
            }
        }

        // 2. Verificar Pacotes de Turbinar
        CLI::write('', 'white');
        CLI::write('🚀 PACOTES DE TURBINAR:', 'green');
        CLI::write('─────────────────────────────────────────────────────────────────', 'yellow');

        $packages = $packageModel->findAll();

        if (empty($packages)) {
            CLI::write('❌ Nenhum pacote encontrado!', 'red');
        } else {
            foreach ($packages as $idx => $package) {
                CLI::write("", 'white');
                CLI::write(sprintf(
                    "%d. %s (%s)",
                    $idx + 1,
                    $package->nome,
                    $package->chave
                ), 'white');
                CLI::write(sprintf(
                    "   💰 Preço: R$ %.2f | ⏱️ Duração: %s | 🏷️ Tipo: %s",
                    $package->preco,
                    $package->duracao_dias ? $package->duracao_dias . ' dias' : 'Variável',
                    $package->tipo_promocao
                ), 'white');
            }
        }

        CLI::write('', 'white');
        CLI::write('╔════════════════════════════════════════════════════════════════╗', 'cyan');
        CLI::write('║                   ✅ VERIFICAÇÃO CONCLUÍDA                    ║', 'cyan');
        CLI::write('╚════════════════════════════════════════════════════════════════╝', 'cyan');
        CLI::write('', 'white');
    }
}
