<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\PaymentGatewayModel;
use App\Models\PaymentGatewayConfigModel;

class GatewayFixEncryption extends BaseCommand
{
    protected $group       = 'Gateway';
    protected $name        = 'gateway:fix-encryption';
    protected $description = 'Re-criptografa as chaves do gateway usando a chave atual do sistema (.env)';

    public function run(array $params)
    {
        CLI::write('🚀 Iniciando reparo de criptografia do Gateway...', 'cyan');

        $gatewayModel = new PaymentGatewayModel();
        $configModel = new PaymentGatewayConfigModel();

        // 1. Localizar o gateway Asaas
        $asaas = $gatewayModel->where('code', 'asaas')->first();

        if (!$asaas) {
            CLI::error('❌ Gateway Asaas não encontrado no banco de dados.');
            return;
        }

        CLI::write("✅ Gateway Asaas encontrado (ID: {$asaas->id}).", 'green');

        // 2. Pegar chaves do .env
        // Usamos getenv ou env() pois o CI já carregou o .env
        $apiKey = getenv('ASAAS_API_KEY');
        $webhookSecret = getenv('ASAAS_WEBHOOK_SECRET');

        if (empty($apiKey)) {
            CLI::error('❌ ASAAS_API_KEY não encontrada nas variáveis de ambiente (.env).');
            return;
        }

        CLI::write('📝 Sincronizando chaves...', 'yellow');

        try {
            // 3. Salvar novamente para acionar o encryptValue do Model
            $configModel->saveConfig($asaas->id, 'api_key', $apiKey, true);
            CLI::write('✅ API Key sincronizada e re-criptografada.', 'green');
            
            if (!empty($webhookSecret)) {
                $configModel->saveConfig($asaas->id, 'webhook_secret', $webhookSecret, true);
                CLI::write('✅ Webhook Secret sincronizado e re-criptografado.', 'green');
            }

            // 4. Corrigir campo environment (não deve ser sensível)
            $asaasEnv = getenv('ASAAS_ENV') ?: 'sandbox';
            $configModel->saveConfig($asaas->id, 'environment', $asaasEnv, false);
            CLI::write('✅ Campo "environment" corrigido (definido como não sensível).', 'green');

            CLI::write("\n🎉 Reparo concluído com sucesso!", 'cyan');
            CLI::write('O erro "Decrypting: authentication failed" deve desaparecer dos logs.', 'white');

        } catch (\Exception $e) {
            CLI::error('❌ Erro ao salvar configurações: ' . $e->getMessage());
        }
    }
}
