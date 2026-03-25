<?php

namespace Tests\E2E\Scenarios;

use Tests\E2E\SubscriptionE2EBase;

/**
 * InitialSignupTest
 * 
 * Scenario 1: Contratação inicial (novo cliente, sem plano)
 * 
 * Flow:
 * 1. Usuário registra conta nova
 * 2. Usuário faz upload de documentos KYC (frente, verso, selfie)
 * 3. Sistema executa verificação facial (liveness)
 * 4. KYCService marca account como VERIFIED
 * 5. Usuário seleciona plano + ciclo faturamento
 * 6. Sistema cria subscription via API Asaas
 * 7. Usuário completa pagamento (cartão teste bem-sucedido)
 * 8. Webhook PAYMENT_CONFIRMED recebido/processado
 * 9. Subscription marcada como ACTIVE
 * 10. Usuário consegue acessar dashboard
 */
class InitialSignupTest extends SubscriptionE2EBase
{
    public function testInitialSignupFlow()
    {
        // ============ STEP 1-2: Criar conta + documentos ============
        $testData = $this->createE2ETestAccount('persona_1_initial');
        $accountId = $testData['accountId'];
        $userId = $testData['userId'];

        $this->assertIsInt($accountId);
        $this->assertIsInt($userId);
        $accountModel = model('App\Models\AccountModel');
        $createdAccount = $accountModel->find($accountId);
        $this->seeInDatabase('accounts', [
            'id' => $accountId,
            'email' => $createdAccount->email,
            'verification_status' => 'NONE',
        ]);

        // ============ STEP 3-4: KYC Verificação ============
        $kycResult = $this->verifyAccountKYC($accountId, withFacial: true);
        
        $this->assertTrue($kycResult['success'], "KYC verification should succeed");
        $this->assertAccountFullyVerified($accountId);

        // ============ STEP 5-6: Criar subscription ============
        // Buscar plano (assumir que existe 'PRO')
        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->where('chave', 'PRO')->first();
        $planPrice = 199.90;
        
        if (!$plan) {
            $uniqueKey = 'PRO_E2E_TEST_' . time();
            // Criar plano para teste se não existir
            $plan = (object) [
                'id' => $planModel->insert([
                    'chave' => $uniqueKey,
                    'nome' => 'Pro Test',
                    'preco_mensal' => 199.90,
                    'carencia_dias' => 0,
                    'ativo' => true,
                ]),
                'preco_mensal' => $planPrice,
            ];
        } else {
            $planPrice = (float) ($plan->preco_mensal ?? $planPrice);
        }

        $subData = $this->createSubscription($accountId, $plan->id, 'MONTHLY');
        $subscriptionId = $subData['subscriptionId'];

        $this->assertIsInt($subscriptionId);
        $this->assertSubscriptionActive($subscriptionId);

        // ============ STEP 7-8: Simular pagamento webhook ============
        $webhookResult = $this->simulateAsaasWebhook('PAYMENT_CONFIRMED', [
            'id' => 'pay_test_' . time(),
            'subscription' => $subscriptionId,
            'status' => 'CONFIRMED',
            'value' => $planPrice,
        ]);

        // Webhook deve retornar 200 OK
        $this->assertEquals(200, $webhookResult['statusCode'], 
            "Webhook should process successfully. Response: " . json_encode($webhookResult)
        );

        // ============ STEP 9-10: Verificar acesso ============
        $accessCheck = $this->checkUserAccess($userId, '/admin/dashboard');
        
        $this->assertTrue($accessCheck['canAccess'], 
            "User should be able to access dashboard after subscription. Message: " . $accessCheck['message']
        );

        // ============ FINAL VERIFICATIONS ============
        $this->seeInDatabase('subscriptions', [
            'id' => $subscriptionId,
            'account_id' => $accountId,
            'status' => 'ACTIVE',
            'plan_id' => $plan->id,
        ]);

        $this->seeInDatabase('accounts', [
            'id' => $accountId,
            'verification_status' => 'VERIFIED',
            'is_verified' => true,
        ]);
    }

    /**
     * Test KYC Rejection - documento inválido
     */
    public function testKYCRejectionInvalidDocument()
    {
        $testData = $this->createE2ETestAccount('persona_1_initial');
        $accountId = $testData['accountId'];

        // Tentar verificação sem documentos
        $kycService = new \App\Services\KYCService();
        
        // Criar account sem os documentos
        $result = $kycService->verifyFacialLiveness($accountId, ['provider' => 'mock']);
        
        // Deve falhar
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('required', strtolower($result['message']));
    }

    /**
     * Test Subscription Block sem KYC
     */
    public function testAccessBlockedWithoutKYC()
    {
        $testData = $this->createE2ETestAccount('persona_1_initial');
        $userId = $testData['userId'];
        $accountId = $testData['accountId'];

        // Criar subscription sem KYC verificado
        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->where('chave', 'PRO')->first();
        if (!$plan) {
            $uniqueKey = 'PRO_E2E_TEST_2_' . time();
            $plan = (object)['id' => $planModel->insert([
                'chave' => $uniqueKey,
                'nome' => 'Pro Test 2',
                'preco_mensal' => 199.90,
                'carencia_dias' => 0,
            ])];
        }

        $this->createSubscription($accountId, $plan->id, 'MONTHLY');

        // Tentar acessar sem KYC
        $accessCheck = $this->checkUserAccess($userId, '/admin/dashboard');
        
        // Deve ser bloqueado
        $this->assertFalse($accessCheck['canAccess'], 
            "User without KYC verification should be blocked from dashboard"
        );
    }

    /**
     * Test Coupon cannot be stacked with Plan Grace
     */
    public function testCouponGraceExclusivity()
    {
        $couponModel = model('App\Models\CouponModel');
        $planModel = model('App\Models\PlanModel');

        // Criar coupon com grace period
        $coupon = (object) [
            'id' => $couponModel->insert([
                'code' => 'TESTCOUPON' . time(),
                'discount_type' => 'percent',
                'discount_value' => 50,
                'carencia_tipo' => 'dias',
                'carencia_valor' => 30,
            ]),
        ];

        // Buscar plano com grace period
        $planWithGraceId = $planModel->insert([
            'chave' => 'PLAN_WITH_GRACE_' . time(),
            'nome' => 'Plan With Grace',
            'preco_mensal' => 199.90,
            'carencia_dias' => 30,
        ]);

        // Tentar aplicar coupon
        $coupon = $couponModel->find($coupon->id);
        $result = $couponModel->canBeAppliedWithPlanGrace($coupon, (int) $planWithGraceId);

        $this->assertFalse($result['isValid'], "Coupon should not be applicable with plan grace period");
        $this->assertStringContainsString('não', strtolower($result['message']));
    }
}
