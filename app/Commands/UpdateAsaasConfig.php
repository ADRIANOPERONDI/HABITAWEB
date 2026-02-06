<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\PaymentGatewayConfigModel;

class UpdateAsaasConfig extends BaseCommand
{
    protected $group       = 'Configuration';
    protected $name        = 'update:asaas-config';
    protected $description = 'Updates Asaas API Key securely';

    public function run(array $params)
    {
        $key = array_shift($params);

        if (empty($key)) {
            CLI::error('Usage: update:asaas-config <api_key>');
            return;
        }

        $configModel = new PaymentGatewayConfigModel();
        
        // Asaas ID is usually 1, but let's find it
        $gatewayModel = model('App\Models\PaymentGatewayModel');
        $gateway = $gatewayModel->where('code', 'asaas')->first();
        
        if (!$gateway) {
            CLI::error('Asaas gateway not found in DB.');
            return;
        }

        try {
            // Save API Key (Sensitive)
            $configModel->saveConfig($gateway->id, 'api_key', $key, true);
            CLI::write('✅ API Key updated and encrypted successfully.', 'green');
            
            // Ensure environment is sandbox
            $configModel->saveConfig($gateway->id, 'environment', 'sandbox', false);
            CLI::write('✅ Environment ensured as sandbox.', 'green');

        } catch (\Exception $e) {
            CLI::error('Error updating config: ' . $e->getMessage());
        }
    }
}
