<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class PaymentGatewaysSeeder extends Seeder
{
    public function run()
    {
        // 1. Limpar tabelas existentes para evitar duplicidade
        // Usamos emptyTable() (DELETE) ao invés de truncate() para evitar erro de FK no Postgres
        $this->db->table('payment_gateway_configs')->emptyTable();
        // Se houver transações, isso pode falhar. Idealmente, não deveríamos deletar gateways se há transações.
        // Mas para este fix crítico de ambiente dev/homolog, vamos tentar limpar.
        // Se falhar por causa de transactions, teremos que truncar transactions também ou fazer update.
        try {
            $this->db->table('payment_gateways')->emptyTable(); 
        } catch (\Exception $e) {
            // Se falhar, tenta update (ou ignora e deixa insert falhar se duplicado code?)
            // Melhor: Se não der para deletar, assumimos que já existe e vamos fazer upsert? 
            // Não, o insert vai falhar.
            // Vamos tentar limpar transactions de teste se necessário?
            // O usuário deletou usuários de teste antes? Talvez.
            // Vamos assumir que emptyTable funciona se configs for limpo, a menos que transactions bloqueie.
            // Se transactions bloquear, vamos limpar transactions também? É arriscado em prod.
            // Mas o erro anterior foi só sobre configs.
            throw $e;
        }

        // Helpers
        $encrypter = \Config\Services::encrypter();
        $encrypt = function($value) use ($encrypter) {
            if (empty($value)) return null;
            return base64_encode($encrypter->encrypt($value));
        };

        // Valores do ambiente
        $asaasApiKey = getenv('ASAAS_API_KEY') ?: $_ENV['ASAAS_API_KEY'] ?? null;
        $asaasWebhookSecret = getenv('ASAAS_WEBHOOK_SECRET') ?: $_ENV['ASAAS_WEBHOOK_SECRET'] ?? null;
        $asaasEnv = getenv('ASAAS_ENV') ?: $_ENV['ASAAS_ENV'] ?? 'sandbox';

        // Gateway: Asaas (Primário e ativo por padrão)
        $this->db->table('payment_gateways')->insert([
            'code' => 'asaas',
            'name' => 'Asaas',
            'class_name' => 'App\\PaymentGateways\\AsaasGateway',
            'is_active' => true,
            'is_primary' => true,
            'logo_url' => null,
            'description' => 'Gateway brasileiro com suporte a PIX, Boleto e Cartão de Crédito',
            'supported_methods' => json_encode(['PIX', 'BOLETO', 'CREDIT_CARD'])
        ]);
        
        $asaasId = $this->db->insertID();
        
        // Configurações do Asaas
        $asaasConfigs = [
            [
                'gateway_id' => $asaasId,
                'config_key' => 'api_key',
                'config_value' => $encrypt($asaasApiKey),
                'config_type' => 'string',
                'is_sensitive' => true,
                'display_order' => 1
            ],
            [
                'gateway_id' => $asaasId,
                'config_key' => 'environment',
                'config_value' => $asaasEnv,
                'config_type' => 'select',
                'is_sensitive' => false,
                'display_order' => 2
            ],
            [
                'gateway_id' => $asaasId,
                'config_key' => 'webhook_secret',
                'config_value' => $encrypt($asaasWebhookSecret),
                'config_type' => 'string',
                'is_sensitive' => true,
                'display_order' => 3
            ]
        ];
        
        $this->db->table('payment_gateway_configs')->insertBatch($asaasConfigs);
        
        // Gateway: Stripe (Desativado por padrão)
        $this->db->table('payment_gateways')->insert([
            'code' => 'stripe',
            'name' => 'Stripe',
            'class_name' => 'App\\PaymentGateways\\StripeGateway',
            'is_active' => false,
            'is_primary' => false,
            'logo_url' => null,
            'description' => 'Gateway internacional com melhor UX para cartão de crédito',
            'supported_methods' => json_encode(['CREDIT_CARD'])
        ]);
        
        $stripeId = $this->db->insertID();
        
        // Configurações do Stripe
        $stripeConfigs = [
            [
                'gateway_id' => $stripeId,
                'config_key' => 'secret_key',
                'config_value' => null,
                'config_type' => 'string',
                'is_sensitive' => true,
                'display_order' => 1
            ],
            [
                'gateway_id' => $stripeId,
                'config_key' => 'publishable_key',
                'config_value' => null,
                'config_type' => 'string',
                'is_sensitive' => false,
                'display_order' => 2
            ],
            [
                'gateway_id' => $stripeId,
                'config_key' => 'webhook_secret',
                'config_value' => null,
                'config_type' => 'string',
                'is_sensitive' => true,
                'display_order' => 3
            ]
        ];
        
        $this->db->table('payment_gateway_configs')->insertBatch($stripeConfigs);
        
        // Gateway: Mercado Pago (Desativado por padrão)
        $this->db->table('payment_gateways')->insert([
            'code' => 'mercadopago',
            'name' => 'Mercado Pago',
            'class_name' => 'App\\PaymentGateways\\MercadoPagoGateway',
            'is_active' => false,
            'is_primary' => false,
            'logo_url' => null,
            'description' => 'Gateway popular na América Latina com PIX e Cartão',
            'supported_methods' => json_encode(['PIX', 'CREDIT_CARD', 'DEBIT_CARD'])
        ]);
        
        $mpId = $this->db->insertID();
        
        // Configurações do Mercado Pago
        $mpConfigs = [
            [
                'gateway_id' => $mpId,
                'config_key' => 'access_token',
                'config_value' => null,
                'config_type' => 'string',
                'is_sensitive' => true,
                'display_order' => 1
            ],
            [
                'gateway_id' => $mpId,
                'config_key' => 'public_key',
                'config_value' => null,
                'config_type' => 'string',
                'is_sensitive' => false,
                'display_order' => 2
            ]
        ];
        
        $this->db->table('payment_gateway_configs')->insertBatch($mpConfigs);
        
        echo "✅ Gateways de pagamento renovados com sucesso!\n";
        echo "   - Asaas (ATIVO e PRIMÁRIO) - Chaves atualizadas via ENV.\n";
        echo "   - Stripe (DESATIVADO)\n";
        echo "   - Mercado Pago (DESATIVADO)\n";
    }
}
