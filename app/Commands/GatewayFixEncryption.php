<?php

namespace App\Commands;

use App\Models\PaymentGatewayConfigModel;
use App\Models\PaymentGatewayModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class GatewayFixEncryption extends BaseCommand
{
    protected $group = 'Gateway';
    protected $name = 'gateway:fix-encryption';
    protected $description = 'Re-encrypts gateway secrets using the current app encryption key.';

    public function run(array $params)
    {
        CLI::write('Re-encrypting Asaas gateway configuration...', 'cyan');

        $gatewayModel = new PaymentGatewayModel();
        $configModel = new PaymentGatewayConfigModel();
        $asaas = $gatewayModel->where('code', 'asaas')->first();

        if (!$asaas) {
            CLI::error('Gateway Asaas not found.');
            return;
        }

        $apiKey = env('ASAAS_API_KEY', '');
        $environment = strtolower((string) env('ASAAS_ENV', 'sandbox'));
        $webhookToken = env('ASAAS_WEBHOOK_TOKEN', env('ASAAS_WEBHOOK_SECRET', ''));
        $webhookSecret = env('ASAAS_WEBHOOK_SECRET', $webhookToken);

        if (!in_array($environment, ['sandbox', 'production'], true)) {
            CLI::error('ASAAS_ENV must be sandbox or production.');
            return;
        }

        if (trim((string) $apiKey) === '') {
            CLI::error('ASAAS_API_KEY not found in .env.');
            return;
        }

        try {
            $configModel->saveConfig($asaas->id, 'api_key', (string) $apiKey, true);
            $configModel->saveConfig($asaas->id, 'environment', $environment, false);
            $configModel->saveConfig($asaas->id, 'webhook_token', (string) $webhookToken, true);
            $configModel->saveConfig($asaas->id, 'webhook_secret', (string) $webhookSecret, true);

            $gatewayModel->update($asaas->id, ['is_active' => true]);
            $gatewayModel->setPrimary($asaas->id);

            CLI::write('Asaas secrets re-encrypted and gateway set as primary.', 'green');
            CLI::write('Environment: ' . $environment, 'white');
        } catch (\Throwable $e) {
            CLI::error('Error saving gateway config: ' . $e->getMessage());
        }
    }
}
