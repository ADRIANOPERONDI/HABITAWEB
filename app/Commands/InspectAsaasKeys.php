<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\PaymentGatewayConfigModel;

class InspectAsaasKeys extends BaseCommand
{
    protected $group       = 'Debug';
    protected $name        = 'debug:inspect-asaas';
    protected $description = 'Inspect actual Asaas keys';

    public function run(array $params)
    {
        $db = \Config\Database::connect();
        $asaas = $db->table('payment_gateways')->where('code', 'asaas')->get()->getRow();
        
        if (!$asaas) {
            CLI::error('Asaas gateway not found');
            return;
        }

        $model = new PaymentGatewayConfigModel();
        $configs = $model->where('gateway_id', $asaas->id)->findAll();
        $encrypter = \Config\Services::encrypter();

        foreach ($configs as $config) {
            CLI::write("Key: {$config->config_key} | Sensitive: " . ($config->is_sensitive ? 'YES' : 'NO'));
            
            if ($config->is_sensitive && !empty($config->config_value)) {
                try {
                    $raw = base64_decode($config->config_value);
                    $decrypted = $encrypter->decrypt($raw);
                    
                    $visible = substr($decrypted, 0, 10) . '...' . substr($decrypted, -4);
                    CLI::write("  Value: $visible", 'green');
                    // Check if it starts with $aact (Production) or sandbox prefix?
                    // Actually Asaas keys usually start with '$aact'
                    CLI::write("  Prefix: " . substr($decrypted, 0, 5));
                    
                } catch (\Exception $e) {
                    CLI::write("  Value: FAIL - " . $e->getMessage(), 'red');
                }
            } else {
                CLI::write("  Value: {$config->config_value}");
            }
            CLI::write("-------------------");
        }
    }
}
