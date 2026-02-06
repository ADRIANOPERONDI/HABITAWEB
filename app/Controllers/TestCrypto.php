<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use App\Models\PaymentGatewayConfigModel;

class TestCrypto extends Controller
{
    public function index()
    {
        $encrypter = \Config\Services::encrypter();
        
        echo "<h1>Diagnóstico de Criptografia</h1>";
        
        $model = new PaymentGatewayConfigModel();
        $configs = $model->findAll();
        
        echo "<table border='1'><tr><th>ID</th><th>Key</th><th>Value (Raw)</th><th>Decrypted</th><th>Status</th></tr>";
        
        foreach ($configs as $config) {
            echo "<tr>";
            echo "<td>{$config->id}</td>";
            echo "<td>{$config->config_key}</td>";
            
            $raw = $config->config_value;
            echo "<td>" . substr($raw, 0, 20) . "...</td>";
            
            $decrypted = 'N/A';
            $status = 'OK';
            
            if ($config->is_sensitive) {
                try {
                    $decrypted = $encrypter->decrypt(base64_decode($raw));
                    // Mascarar para segurança
                    $decrypted = substr($decrypted, 0, 5) . '***' . substr($decrypted, -3);
                } catch (\Exception $e) {
                    $status = 'ERROR: ' . $e->getMessage();
                    $decrypted = 'FALHA';
                }
            } else {
                $decrypted = $raw;
            }
            
            echo "<td>{$decrypted}</td>";
            echo "<td style='color: " . ($status == 'OK' ? 'green' : 'red') . "'>{$status}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}
