<?php

namespace App\Controllers;

use CodeIgniter\Controller;

class TestEncryption extends Controller
{
    public function index()
    {
        echo "<h1>Test Encryption in Web Context</h1>";
        
        $encrypter = \Config\Services::encrypter();
        
        // Ler a config do banco
        $db = \Config\Database::connect();
        $config = $db->table('payment_gateway_configs')
                    ->where('config_key', 'api_key')
                    ->get()
                    ->getRow();
        
        if (!$config) {
            echo "<p style='color:red'>❌ Nenhuma api_key encontrada!</p>";
            return;
        }
        
        echo "<p>✅ Config encontrada no banco</p>";
        echo "<p>Valor criptografado (50 chars): " . substr($config->config_value, 0, 50) . "...</p>";
        
        // Tentar descriptografar
        try {
            $decrypted = $encrypter->decrypt(base64_decode($config->config_value));
            echo "<p style='color:green'>✅ SUCESSO! Descriptografado no contexto WEB</p>";
            echo "<p>Valor: " . substr($decrypted, 0, 20) . "...</p>";
        } catch (\Exception $e) {
            echo "<p style='color:red'>❌ FALHA: " . $e->getMessage() . "</p>";
            
            // Tentar ler a chave do ENV
            $envKey = getenv('ASAAS_API_KEY') ?: $_ENV['ASAAS_API_KEY'] ?? 'VAZIO';
            echo "<p>ENV Key (30 chars): " . substr($envKey, 0, 30) . "...</p>";
            
            // Verificar encryption key
            $encKey = getenv('encryption.key') ?: $_ENV['encryption.key'] ?? config('Encryption')->key ?? 'VAZIO';
            echo "<p>Encryption Key (30 chars): " . substr($encKey, 0, 30) . "...</p>";
        }
    }
}
