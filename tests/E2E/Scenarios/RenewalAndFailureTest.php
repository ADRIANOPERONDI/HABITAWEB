<?php

namespace Tests\E2E\Scenarios;

use Tests\E2E\SubscriptionE2EBase;

/**
 * SuccessfulRenewalTest
 * 
 * Scenario 2: Renovação com sucesso (pagamento aprovado)
 * 
 * Flow:
 * 1. Account com subscription ativa pré-existente
 * 2. Próxima data de pagamento chega
 * 3. Sistema cria invoice no Asaas
 * 4. Webhook: PAYMENT_CONFIRMED recebido
 * 5. Subscription.data_fim atualizada (+ 1 período)
 * 6. Subscription.proximo_pagamento atualizada
 * 7. Usuário ainda pode acessar dashboard
 */
class SuccessfulRenewalTest extends SuccessfulRenewalTest
{
    public function testSuccessfulRenewalFlow()
    {
        // ============ STEP 1: Setup - Conta com subscription ativa ============
        $testData = $this->createTestAccount('persona_2_renewal');
        $accountId = $testData['accountId'];
        $userId = $testData['userId'];

        // Verificar KYC
        $this->verifyAccountKYC($accountId, withFacial: true);

        // Criar subscription "pré-existente"  
        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->where('chave', 'PRO')->first();
        if (!$plan) {
            $plan = (object)['id' => $planModel->insert([
                'chave' => 'PRO_RENEWAL',
                'nome' => 'Pro Renewal',
                'preco_mensal' => 199.90,
                'carencia_dias' => 0,
            ])];
        }

        $originalSub = $this->createSubscription($accountId, $plan->id, 'MONTHLY');
        $subscriptionId = $originalSub['subscriptionId'];
        $originalEndDate = $originalSub['data']->data_fim;

        $this->assertSubscriptionActive($subscriptionId);

        // ============ STEP 2-3: Simular chegada de data de renovação ============
        // Em produção, isso seria triggers por scheduler/cron
        // Para teste, apenas simular que a data passou

        $subModel = model('App\Models\SubscriptionModel');
        $subscription = $subModel->find($subscriptionId);

        // ============ STEP 4: Simular webhook de pagamento confirmado ============
        $webhookResult = $this->simulateAsaasWebhook('PAYMENT_CONFIRMED', [
            'id' => 'pay_renewal_' . time(),
            'subscription' => $subscriptionId,
            'status' => 'CONFIRMED',
            'value' => $plan->preco_mensal,
            'dueDate' => $subscription->proximo_pagamento,
            'paymentDate' => date('Y-m-d'),
        ]);

        $this->assertEquals(200, $webhookResult['statusCode']);

        // ============ STEP 5-6: Verificar atualização de datas ============
        $updatedSub = $subModel->find($subscriptionId);

        // data_fim deve ter avançado em 1 mês
        $originalEnd = new \DateTime($originalEndDate);
        $updatedEnd = new \DateTime($updatedSub->data_fim);
        $interval = $originalEnd->diff($updatedEnd);

        $this->assertTrue($interval->days >= 28 && $interval->days <= 32, 
            "Subscription end date should advance by ~1 month (got {$interval->days} days)"
        );

        // proximo_pagamento deve ser atualizado
        $this->assertNotEquals(
            $subscription->proximo_pagamento,
            $updatedSub->proximo_pagamento,
            "Next payment date should be updated"
        );

        // ============ STEP 7: Verificar acesso ============
        $accessCheck = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertTrue($accessCheck['canAccess']);

        // ============ FINAL VERIFICATIONS ============
        $this->seeInDatabase('subscriptions', [
            'id' => $subscriptionId,
            'status' => 'ACTIVE',
        ]);
    }
}

/**
 * FailedPaymentRecoveryTest
 * 
 * Scenario 3: Falha de pagamento + recuperação
 * 
 * Flow:
 * 1. Subscription próxima renovação chega
 * 2. Webhook: PAYMENT_FAILED recebido (cartão recusado)
 * 3. Subscription.status = SUSPENDED
 * 4. Conta entra em período de carência de 3 dias
 * 5. Usuário é bloqueado do dashboard (com mensagem "Pagamento pendente")
 * 6. Asaas tenta retry automático
 * 7. Webhook: PAYMENT_CONFIRMED (retry bem-sucedido)
 * 8. Subscription.status = ACTIVE
 * 9. Usuário consegue acessar dashboard novamente
 */
class FailedPaymentRecoveryTest extends SubscriptionE2EBase
{
    public function testFailedPaymentThenRecovery()
    {
        // ============ STEP 1: Setup ============
        $testData = $this->createTestAccount('persona_3_failed_recovery');
        $accountId = $testData['accountId'];
        $userId = $testData['userId'];

        $this->verifyAccountKYC($accountId, withFacial: true);

        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->where('chave', 'PRO')->first();
        if (!$plan) {
            $plan = (object)['id' => $planModel->insert([
                'chave' => 'PRO_FAILED_RECOVERY',
                'nome' => 'Pro Failed',
                'preco_mensal' => 199.90,
                'carencia_dias' => 0,
            ])];
        }

        $originalSub = $this->createSubscription($accountId, $plan->id, 'MONTHLY');
        $subscriptionId = $originalSub['subscriptionId'];

        // ============ STEP 2: Webhook - Pagamento falho ============
        $webhookResult = $this->simulateAsaasWebhook('PAYMENT_FAILED', [
            'id' => 'pay_failed_' . time(),
            'subscription' => $subscriptionId,
            'status' => 'FAILED',
            'value' => $plan->preco_mensal,
            'failureReason' => 'Card declined',
        ]);

        $this->assertEquals(200, $webhookResult['statusCode']);

        // ============ STEP 3: Verificar status SUSPENDED ============
        $subModel = model('App\Models\SubscriptionModel');
        $suspendedSub = $subModel->find($subscriptionId);

        $this->assertEquals('SUSPENDED', $suspendedSub->status, 
            "Subscription should be SUSPENDED after failed payment"
        );

        // ============ STEP 4: Verificar que usuário está bloqueado (não na carência) ============
        $accessCheck = $this->checkUserAccess($userId, '/admin/dashboard');

        // Com status SUSPENDED e sem grace period, deve ser bloqueado
        // (AdminAuth filter deveria redirecionar para checkout)
        $this->assertTrue(
            $accessCheck['statusCode'] !== 200 || $accessCheck['canAccess'] === false,
            "User should be blocked during payment failure"
        );

        // ============ STEP 5-6: Simular retry bem-sucedido ============
        // Em produção, Asaas faria retry automático dias 3 e 5
        // Aqui, simulamos webhook de sucesso
        
        $retryWebhookResult = $this->simulateAsaasWebhook('PAYMENT_CONFIRMED', [
            'id' => 'pay_retry_' . time(),
            'subscription' => $subscriptionId,
            'status' => 'CONFIRMED',
            'value' => $plan->preco_mensal,
            'paymentDate' => date('Y-m-d'),
        ]);

        $this->assertEquals(200, $retryWebhookResult['statusCode']);

        // ============ STEP 7: Verificar status ACTIVE novamente ============
        $recoveredSub = $subModel->find($subscriptionId);

        $this->assertEquals('ACTIVE', $recoveredSub->status, 
            "Subscription should be ACTIVE after successful retry"
        );

        // ============ STEP 8: Verificar acesso restaurado ============
        $accessRestored = $this->checkUserAccess($userId, '/admin/dashboard');

        $this->assertTrue($accessRestored['canAccess'], 
            "User should have access restored after payment recovery"
        );
    }

    /**
     * Test - Pagamento falha e nunca é recuperado (expiração de carência)
     */
    public function testPaymentFailureGracePeriodExpiry()
    {
        $testData = $this->createTestAccount('persona_3_failed_recovery');
        $accountId = $testData['accountId'];
        $userId = $testData['userId'];

        $this->verifyAccountKYC($accountId, withFacial: true);

        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->where('chave', 'PRO')->first();
        if (!$plan) {
            $plan = (object)['id' => $planModel->insert([
                'chave' => 'PRO_GRACE_EXPIRY',
                'nome' => 'Pro Grace Expiry',
                'preco_mensal' => 199.90,
                'carencia_dias' => 0,
            ])];
        }

        $originalSub = $this->createSubscription($accountId, $plan->id, 'MONTHLY');
        $subscriptionId = $originalSub['subscriptionId'];

        // Simular pagamento falho
        $this->simulateAsaasWebhook('PAYMENT_FAILED', [
            'id' => 'pay_fail_expiry_' . time(),
            'subscription' => $subscriptionId,
            'status' => 'FAILED',
        ]);

        // Simular que carência expirou (>3 dias) SEM recuperação
        // Atualizar no DB para simular isto
        $subModel = model('App\Models\SubscriptionModel');
        $subModel->update($subscriptionId, [
            'proximo_pagamento' => (new \DateTime())->modify('-4 days')->format('Y-m-d'),
        ]);

        // Usuário deve estar bloqueado
        $accessCheck = $this->checkUserAccess($userId, '/admin/dashboard');

        $this->assertFalse($accessCheck['canAccess'], 
            "User should still be blocked after grace period expiry"
        );
    }
}
