<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\PaymentGatewayConfigModel;

class GatewayDebugConfig extends BaseCommand
{
    protected $group       = 'Gateway';
    protected $name        = 'gateway:debug-config';
    protected $description = 'Inspeciona as configurações do gateway e testa a descriptografia campo a campo.';

    public function run(array $params)
    {
        $configModel = new PaymentGatewayConfigModel();
        $configs = $configModel->findAll();

        CLI::write(str_pad('ID', 5) . str_pad('KEY', 25) . str_pad('SENSITIVE', 10) . 'STATUS', 'yellow');
        CLI::write(str_repeat('-', 60));

        $encrypter = \Config\Services::encrypter();

        foreach ($configs as $config) {
            $status = 'OK';
            $error = '';

            if ($config->is_sensitive && !empty($config->config_value)) {
                try {
                    $val = base64_decode($config->config_value);
                    $encrypter->decrypt($val);
                    $status = '✅ DECRYPT OK';
                } catch (\Exception $e) {
                    $status = '❌ FAIL';
                    $error = $e->getMessage();
                }
            } else {
                $status = 'N/A (Not sensitive)';
            }

            CLI::write(
                str_pad($config->id, 5) . 
                str_pad($config->config_key, 25) . 
                str_pad($config->is_sensitive ? 'YES' : 'NO', 10) . 
                $status . ($error ? " ($error)" : ''),
                $status === '❌ FAIL' ? 'red' : 'white'
            );
        }
    }
}
