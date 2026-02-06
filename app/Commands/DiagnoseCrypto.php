<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class DiagnoseCrypto extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'crypto:diagnose';
    protected $description = 'Diagnostica problemas de criptografia no payment gateway';

    public function run(array $params)
    {
        CLI::write('=== DIAGNÓSTICO DE CRIPTOGRAFIA ===', 'yellow');
        CLI::newLine();

        $db = \Config\Database::connect();
        $encrypter = \Config\Services::encrypter();

        // 1. Buscar a api_key do banco
        $builder = $db->table('payment_gateway_configs');
        $config = $builder->where('config_key', 'api_key')->get()->getRow();

        if (!$config) {
            CLI::error('❌ ERRO: Nenhuma api_key encontrada no banco!');
            return;
        }

        CLI::write("✅ Config encontrada no banco:", 'green');
        CLI::write("   ID: {$config->id}");
        CLI::write("   Gateway ID: {$config->gateway_id}");
        CLI::write("   Is Sensitive: " . ($config->is_sensitive ? 'SIM' : 'NÃO'));
        CLI::write("   Valor criptografado (primeiros 50 chars): " . substr($config->config_value, 0, 50) . "...");
        CLI::newLine();

        // 2. Tentar descriptografar
        CLI::write('Tentando descriptografar...', 'yellow');
        try {
            $decrypted = $encrypter->decrypt(base64_decode($config->config_value));
            CLI::write("✅ SUCESSO! Chave descriptografada.", 'green');
            CLI::write("   Valor (primeiros 20 chars): " . substr($decrypted, 0, 20) . "...");
            return;
        } catch (\Exception $e) {
            CLI::error("❌ FALHA AO DESCRIPTOGRAFAR!");
            CLI::error("   Erro: " . $e->getMessage());
            CLI::newLine();
            
            // 3. Verificar se o valor do ENV está chegando
            $envKey = getenv('ASAAS_API_KEY') ?: $_ENV['ASAAS_API_KEY'] ?? 'VAZIO';
            CLI::write("Valor da ASAAS_API_KEY no ENV: " . substr($envKey, 0, 30) . "...");
            CLI::newLine();
            
            // 4. Tentar criptografar o valor do ENV e ver se é igual ao do banco
            CLI::write('Testando criptografar o valor atual do ENV...', 'yellow');
            $freshEncrypted = base64_encode($encrypter->encrypt($envKey));
            
            if ($freshEncrypted === $config->config_value) {
                CLI::write("✅ Os valores criptografados SÃO IGUAIS - problema em outro lugar!", 'green');
            } else {
                CLI::error("❌ Os valores criptografados SÃO DIFERENTES!");
                CLI::error("   Isso confirma que os dados foram criptografados com OUTRA chave de encryption.");
                CLI::newLine();
                CLI::write("🔧 SOLUÇÃO: Executar 'php spark crypto:fix' para recriptografar com a chave atual.", 'yellow');
            }
        }
    }
}
