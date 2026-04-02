<?php
/**
 * Script para verificar os planos e pacotes de turbinar criados
 * 
 * Uso: php verify_seeders.php
 */

// Incluir bootstrap do CodeIgniter
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app/Config/Constants.php';

// Carregar Database Config
$config = new \Config\Database();
$db = $config->connect();

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║           VERIFICAÇÃO DE PLANOS E PACOTES DE TURBINAR         ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// 1. Verificar Planos
echo "📋 PLANOS CRIADOS:\n";
echo "─────────────────────────────────────────────────────────────────\n";

$plans = $db->query('SELECT id, chave, nome, preco_mensal, preco_anual, limite_imoveis_ativos, destaques_mensais FROM plans ORDER BY id')->getResult();

if (empty($plans)) {
    echo "❌ Nenhum plano encontrado!\n";
} else {
    foreach ($plans as $idx => $plan) {
        echo sprintf(
            "%d. %s (%s)\n   💰 Mensal: R\$ %.2f | Anual: R\$ %.2f\n   📦 Anúncios: %s | ⭐ Destaques: %d\n\n",
            $idx + 1,
            $plan->nome,
            $plan->chave,
            $plan->preco_mensal,
            $plan->preco_anual,
            $plan->limite_imoveis_ativos ?? 'Ilimitado',
            $plan->destaques_mensais
        );
    }
}

// 2. Verificar Pacotes de Turbinar
echo "\n🚀 PACOTES DE TURBINAR:\n";
echo "─────────────────────────────────────────────────────────────────\n";

$packages = $db->query('SELECT id, chave, nome, tipo_promocao, duracao_dias, preco FROM promotion_packages ORDER BY id')->getResult();

if (empty($packages)) {
    echo "❌ Nenhum pacote encontrado!\n";
} else {
    foreach ($packages as $idx => $package) {
        echo sprintf(
            "%d. %s (%s)\n   💰 Preço: R\$ %.2f | ⏱️ Duração: %s | 🏷️ Tipo: %s\n\n",
            $idx + 1,
            $package->nome,
            $package->chave,
            $package->preco,
            $package->duracao_dias ? $package->duracao_dias . ' dias' : 'Variável',
            $package->tipo_promocao
        );
    }
}

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                   ✅ VERIFICAÇÃO CONCLUÍDA                    ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";
