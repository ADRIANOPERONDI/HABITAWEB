<?php

namespace Tests\Fixtures;

/**
 * SubscriptionTestData - Dados de teste E2E com sandbox Asaas
 * 
 * Contém 8 personas distintas para testes isolados de cenários de subscription
 * +dados de cartão sandbox do Asaas
 */
class SubscriptionTestData
{
    // ====== TEST PERSONAS (8 personas com CPF/email distintos) ======
    
    public static function getTestPersonas(): array
    {
        return [
            // Scenario 1: Initial Signup
            'persona_1_initial' => [
                'name' => 'João da Silva',
                'cpf' => '11144455566', // Test CPF (fake)
                'email' => 'joao.silva+teste1@example.com',
                'phone' => '11999999001',
                'tipo_conta' => 'PF', // Pessoa Física
                'scenario' => 'Contratação Inicial'
            ],
            
            // Scenario 2: Successful Renewal
            'persona_2_renewal' => [
                'name' => 'Maria Santos',
                'cpf' => '22255566677',
                'email' => 'maria.santos+teste2@example.com',
                'phone' => '11999999002',
                'tipo_conta' => 'PF',
                'scenario' => 'Renovação com Sucesso',
                'preexisting_subscription' => true
            ],

            // Scenario 3: Failed Payment + Recovery
            'persona_3_failed_recovery' => [
                'name' => 'Carlos Oliveira',
                'cpf' => '33366677788',
                'email' => 'carlos.oliveira+teste3@example.com',
                'phone' => '11999999003',
                'tipo_conta' => 'PF',
                'scenario' => 'Falha de Pagamento + Recuperação'
            ],

            // Scenario 4: Grace Period Expiration
            'persona_4_grace_expired' => [
                'name' => 'Ana Costa',
                'cpf' => '44477788899',
                'email' => 'ana.costa+teste4@example.com',
                'phone' => '11999999004',
                'tipo_conta' => 'PF',
                'scenario' => 'Carência Expirada'
            ],

            // Scenario 5: Plan Grace Period
            'persona_5_plan_grace' => [
                'name' => 'Pedro Ferreira',
                'cpf' => '55588899900',
                'email' => 'pedro.ferreira+teste5@example.com',
                'phone' => '11999999005',
                'tipo_conta' => 'PF',
                'scenario' => 'Contratação com Carência no Plano'
            ],

            // Scenario 6: Coupon Grace Period
            'persona_6_coupon_grace' => [
                'name' => 'Fernanda Lima',
                'cpf' => '66699900011',
                'email' => 'fernanda.lima+teste6@example.com',
                'phone' => '11999999006',
                'tipo_conta' => 'PF',
                'scenario' => 'Cupom com Carência'
            ],

            // Scenario 7: Plan Upgrade
            'persona_7_upgrade' => [
                'name' => 'Roberto Alves',
                'cpf' => '77700011122',
                'email' => 'roberto.alves+teste7@example.com',
                'phone' => '11999999007',
                'tipo_conta' => 'PF',
                'scenario' => 'Upgrade de Plano',
                'preexisting_subscription' => true
            ],

            // Scenario 8: Cancellation + Reactivation
            'persona_8_cancel_reactiv' => [
                'name' => 'Juliana Martins',
                'cpf' => '88811122233',
                'email' => 'juliana.martins+teste8@example.com',
                'phone' => '11999999008',
                'tipo_conta' => 'PF',
                'scenario' => 'Cancelamento e Reativação'
            ],
        ];
    }

    /**
     * Cartões de teste do Asaas (Sandbox)
     * Veja: https://docs.asaas.com/reference/cartao-de-credito
     */
    public static function getAsaasTestCards(): array
    {
        return [
            'success' => [
                'number' => '4111111111111111',
                'expiry_month' => '12',
                'expiry_year' => '2026',
                'cvv' => '123',
                'description' => 'Aprovado'
            ],
            'decline' => [
                'number' => '4000000000000002',
                'expiry_month' => '12',
                'expiry_year' => '2026',
                'cvv' => '123',
                'description' => 'Recusado'
            ],
            '3d_secure' => [
                'number' => '4000002500003155',
                'expiry_month' => '12',
                'expiry_year' => '2026',
                'cvv' => '123',
                'description' => '3D Secure (2FA)'
            ],
            'insufficient_funds' => [
                'number' => '4000000000000069',
                'expiry_month' => '12',
                'expiry_year' => '2026',
                'cvv' => '123',
                'description' => 'Sem saldo'
            ],
        ];
    }

    /**
     * Credenciais e endpoints Asaas (Sandbox)
     */
    public static function getAsaasConfig(): array
    {
        return [
            'env' => 'sandbox',
            'base_url' => 'https://sandbox.asaas.com/api/v3',
            'api_token' => env('ASAAS_API_TOKEN', 'your_sandbox_token_here'),
            'webhook_token' => env('ASAAS_WEBHOOK_TOKEN', 'your_webhook_token_here'),
        ];
    }

    /**
     * Retorna um persona aleatório para teste isolado
     */
    public static function getRandomPersona(): array
    {
        $personas = self::getTestPersonas();
        $randomKey = array_rand($personas);
        return $personas[$randomKey];
    }

    /**
     * Retorna persona específica por ID (1-8)
     */
    public static function getPersonaById(int $id): array
    {
        $personas = self::getTestPersonas();
        $key = 'persona_' . $id . '_*';
        
        // Find matching persona
        foreach ($personas as $k => $persona) {
            if (str_starts_with($k, 'persona_' . $id)) {
                return $persona;
            }
        }
        
        throw new \Exception("Persona #{$id} não encontrada");
    }

    /**
     * Mock KYC documents para teste (base64 encoded placeholder images)
     * Em produção, usar imagens reais de identidades
     */
    public static function getMockDocumentImages(): array
    {
        // Imagem JPEG simples 644x480 como placeholder
        $minimalJpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP/bAEMAAQEBAQEBAQEBAQEBAQEBAQEBAQEBCA===' 
        );

        return [
            'id_front' => $minimalJpeg,
            'id_back' => $minimalJpeg,
            'selfie' => $minimalJpeg,
        ];
    }

    /**
     * Dados de cupom para testes
     */
    public static function getTestCopons(): array
    {
        return [
            'promo_30_days' => [
                'code' => 'PROMO30E2E',
                'discount_type' => 'percent',
                'discount_value' => 50,
                'carencia_tipo' => 'dias',
                'carencia_valor' => 30,
            ],
            'fixed_100_reais' => [
                'code' => 'FIXED100E2E',
                'discount_type' => 'fixed',
                'discount_value' => 100,
                'carencia_tipo' => null,
                'carencia_valor' => null,
            ],
        ];
    }

    /**
     * Planos disponíveis para teste
     */
    public static function getTestPlans(): array
    {
        return [
            'basic' => [
                'chave' => 'BASIC',
                'nome' => 'Plano Básico',
                'preco_mensal' => 99.90,
                'carencia_dias' => 0, // Sem carência
            ],
            'pro' => [
                'chave' => 'PRO',
                'nome' => 'Plano Pro',
                'preco_mensal' => 199.90,
                'carencia_dias' => 0,
            ],
            'pro_with_grace' => [
                'chave' => 'PRO_GRACE_30',
                'nome' => 'Plano Pro (30 dias grátis)',
                'preco_mensal' => 199.90,
                'carencia_dias' => 30, // 30 dias de carência
            ],
        ];
    }
}
