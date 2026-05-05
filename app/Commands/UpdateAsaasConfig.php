<?php

namespace App\Commands;

use App\Models\PaymentGatewayConfigModel;
use App\Models\PaymentGatewayModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class UpdateAsaasConfig extends BaseCommand
{
    protected $group = 'Configuration';
    protected $name = 'update:asaas-config';
    protected $description = 'Synchronizes Asaas config from params or .env and makes it the primary gateway.';

    public function run(array $params)
    {
        $apiKey = $params[0] ?? env('ASAAS_API_KEY', '');
        $environment = strtolower((string) ($params[1] ?? env('ASAAS_ENV', 'sandbox')));
        $webhookToken = $params[2] ?? env('ASAAS_WEBHOOK_TOKEN', env('ASAAS_WEBHOOK_SECRET', ''));
        $webhookSecret = $params[3] ?? env('ASAAS_WEBHOOK_SECRET', $webhookToken);

        if (!in_array($environment, ['sandbox', 'production'], true)) {
            CLI::error('Environment must be sandbox or production.');
            return;
        }

        if (trim((string) $apiKey) === '') {
            CLI::error('ASAAS_API_KEY is empty. Set it in .env or pass it as the first argument.');
            return;
        }

        $gatewayModel = new PaymentGatewayModel();
        $configModel = new PaymentGatewayConfigModel();
        $gateway = $gatewayModel->where('code', 'asaas')->first();

        if (!$gateway) {
            CLI::error('Asaas gateway not found in DB. Run php spark migrate first.');
            return;
        }

        try {
            $configModel->saveConfig($gateway->id, 'api_key', (string) $apiKey, true);
            $configModel->saveConfig($gateway->id, 'environment', $environment, false);
            $configModel->saveConfig($gateway->id, 'webhook_token', (string) $webhookToken, true);
            $configModel->saveConfig($gateway->id, 'webhook_secret', (string) $webhookSecret, true);

            $gatewayModel->update($gateway->id, [
                'is_active' => true,
                'supported_methods' => json_encode(['PIX', 'BOLETO', 'CREDIT_CARD']),
            ]);
            $gatewayModel->setPrimary($gateway->id);

            CLI::write('Asaas config synchronized.', 'green');
            CLI::write('Environment: ' . $environment, 'white');
            CLI::write('Gateway Asaas is active and primary.', 'green');
        } catch (\Throwable $e) {
            CLI::error('Error updating Asaas config: ' . $e->getMessage());
        }
    }
}
