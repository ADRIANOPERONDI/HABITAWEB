<?php

namespace Tests\E2E\Scenarios;

use Tests\E2E\SubscriptionE2EBase;

/**
 * Scenario 3: Falha de pagamento + recuperação
 */
class FailedPaymentRecoveryTest extends SubscriptionE2EBase
{
    public function testFailedPaymentThenRecovery()
    {
        $testData = $this->createE2ETestAccount('persona_3_failed_recovery');
        $accountId = $testData['accountId'];
        $userId = $testData['userId'];

        $this->verifyAccountKYC($accountId, withFacial: true);

        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->where('chave', 'PRO')->first();
        if (!$plan) {
            $plan = (object) ['id' => $planModel->insert([
                'chave' => 'PRO_FAILED_RECOVERY_' . time(),
                'nome' => 'Pro Failed',
                'preco_mensal' => 199.90,
                'carencia_dias' => 0,
            ]), 'preco_mensal' => 199.90];
        }

        $originalSub = $this->createSubscription($accountId, $plan->id, 'MONTHLY');
        $subscriptionId = $originalSub['subscriptionId'];

        $webhookResult = $this->simulateAsaasWebhook('PAYMENT_FAILED', [
            'id' => 'pay_failed_' . time(),
            'subscription' => $subscriptionId,
            'status' => 'FAILED',
            'value' => (float) ($plan->preco_mensal ?? 199.90),
            'failureReason' => 'Card declined',
        ]);

        $this->assertEquals(200, $webhookResult['statusCode']);

        $subModel = model('App\Models\SubscriptionModel');
        $suspendedSub = $subModel->find($subscriptionId);

        $this->assertEquals('SUSPENDED', $suspendedSub->status,
            'Subscription should be SUSPENDED after failed payment'
        );

        $accessCheck = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertFalse($accessCheck['canAccess']);

        $retryWebhookResult = $this->simulateAsaasWebhook('PAYMENT_CONFIRMED', [
            'id' => 'pay_retry_' . time(),
            'subscription' => $subscriptionId,
            'status' => 'CONFIRMED',
            'value' => (float) ($plan->preco_mensal ?? 199.90),
            'paymentDate' => date('Y-m-d'),
        ]);

        $this->assertEquals(200, $retryWebhookResult['statusCode']);

        $recoveredSub = $subModel->find($subscriptionId);
        $this->assertEquals('ACTIVE', $recoveredSub->status);

        $accessRestored = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertTrue($accessRestored['canAccess']);
    }

    public function testPaymentFailureGracePeriodExpiry()
    {
        $testData = $this->createE2ETestAccount('persona_3_failed_recovery');
        $accountId = $testData['accountId'];
        $userId = $testData['userId'];

        $this->verifyAccountKYC($accountId, withFacial: true);

        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->where('chave', 'PRO')->first();
        if (!$plan) {
            $plan = (object) ['id' => $planModel->insert([
                'chave' => 'PRO_GRACE_EXPIRY_' . time(),
                'nome' => 'Pro Grace Expiry',
                'preco_mensal' => 199.90,
                'carencia_dias' => 0,
            ])];
        }

        $originalSub = $this->createSubscription($accountId, $plan->id, 'MONTHLY');
        $subscriptionId = $originalSub['subscriptionId'];

        $this->simulateAsaasWebhook('PAYMENT_FAILED', [
            'id' => 'pay_fail_expiry_' . time(),
            'subscription' => $subscriptionId,
            'status' => 'FAILED',
        ]);

        $subModel = model('App\Models\SubscriptionModel');
        $subModel->update($subscriptionId, [
            'proximo_pagamento' => (new \DateTime())->modify('-4 days')->format('Y-m-d'),
        ]);

        $accessCheck = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertFalse($accessCheck['canAccess']);
    }
}
