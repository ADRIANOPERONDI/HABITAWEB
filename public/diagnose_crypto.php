<?php

/**
 * Script de diagnóstico definitivo
 * Testa se a chave de encryption consegue descriptografar os dados do banco
 */

require __DIR__ . '/../vendor/autoload.php';

// Bootstrap mínimo do CI4
$paths = new Config\Paths();
$bootstrap = \CodeIgniter\Boot::bootWeb($paths);

// Pegar conexão e encrypter
$db = \Config\Database::connect();
$encrypter = \Config\Services::encrypter();

echo "=== DIAGNÓSTICO DE CRIPTOGRAFIA ===\n\n";

// 1. Buscar a api_key do banco
$builder = $db->table('payment_gateway_configs');
$config = $builder->where('config_key', 'api_key')->get()->getRow();

if (!$config) {
    echo "❌ ERRO: Nenhuma api_key encontrada no banco!\n";
    exit(1);
}

echo "✅ Config encontrada no banco:\n";
echo "   ID: {$config->id}\n";
echo "   Gateway ID: {$config->gateway_id}\n";
echo "   Is Sensitive: " . ($config->is_sensitive ? 'SIM' : 'NÃO') . "\n";
echo "   Valor criptografado (primeiros 50 chars): " . substr($config->config_value, 0, 50) . "...\n\n";

// 2. Tentar descriptografar
echo "Tentando descriptografar...\n";
try {
    $decrypted = $encrypter->decrypt(base64_decode($config->config_value));
    echo "✅ SUCESSO! Chave descriptografada.\n";
    echo "   Valor (primeiros 20 chars): " . substr($decrypted, 0, 20) . "...\n";
    exit(0);
} catch (\Exception $e) {
    echo "❌ FALHA AO DESCRIPTOGRAFAR!\n";
    echo "   Erro: " . $e->getMessage() . "\n\n";
    
    // 3. Verificar se o valor do ENV está chegando
    $envKey = getenv('ASAAS_API_KEY') ?: $_ENV['ASAAS_API_KEY'] ?? 'VAZIO';
    echo "Valor da ASAAS_API_KEY no ENV: " . substr($envKey, 0, 30) . "...\n\n";
    
    // 4. Tentar criptografar o valor do ENV e ver se é igual ao do banco
    echo "Testando criptografar o valor atual do ENV...\n";
    $freshEncrypted = base64_encode($encrypter->encrypt($envKey));
    
    if ($freshEncrypted === $config->config_value) {
        echo "✅ Os valores SÃO IGUAIS - problema em outro lugar!\n";
    } else {
        echo "❌ Os valores SÃO DIFERENTES - chave de encryption mudou!\n";
        echo "   Isso confirma que os dados foram criptografados com outra chave.\n";
        echo "   SOLUÇÃO: Precisa recriptografar os dados com a chave atual.\n";
    }
    
    exit(1);
}
