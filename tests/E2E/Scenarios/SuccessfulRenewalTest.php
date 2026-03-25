<?php

namespace Tests\E2E\Scenarios;

use Tests\E2E\SubscriptionE2EBase;

/**
 * Scenario 2: Renovação com sucesso (pagamento aprovado)
 */
class SuccessfulRenewalTest extends SubscriptionE2EBase
{
    public function testSuccessfulRenewalFlow()
    {
        $testData = $this->createE2ETestAccount('persona_2_renewal');
        $accountId = $testData['accountId'];
        $userId = $testData['userId'];

        $this->verifyAccountKYC($accountId, withFacial: true);

        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->where('chave', 'PRO')->first();
        if (!$plan) {
            $plan = (object) ['id' => $planModel->insert([
                'chave' => 'PRO_RENEWAL_' . time(),
                'nome' => 'Pro Renewal',
                'preco_mensal' => 199.90,
                'carencia_dias' => 0,
            ]), 'preco_mensal' => 199.90];
        }

        $originalSub = $this->createSubscription($accountId, $plan->id, 'MONTHLY');
        $subscriptionId = $originalSub['subscriptionId'];
        $originalEndDate = $originalSub['data']->data_fim;

        $this->assertSubscriptionActive($subscriptionId);

        $subModel = model('App\Models\SubscriptionModel');
        $subscription = $subModel->find($subscriptionId);

        $webhookResult = $this->simulateAsaasWebhook('PAYMENT_CONFIRMED', [
            'id' => 'pay_renewal_' . time(),
            'subscription' => $subscriptionId,
            'status' => 'CONFIRMED',
            'value' => (float) ($plan->preco_mensal ?? 199.90),
            'dueDate' => $subscription->proximo_pagamento,
            'paymentDate' => date('Y-m-d'),
        ]);

        $this->assertEquals(200, $webhookResult['statusCode']);

        $updatedSub = $subModel->find($subscriptionId);

        $originalEnd = new \DateTime($originalEndDate);
        $updatedEnd = new \DateTime($updatedSub->data_fim);
        $interval = $originalEnd->diff($updatedEnd);

        $this->assertTrue($interval->days >= 28 && $interval->days <= 32,
            "Subscription end date should advance by ~1 month (got {$interval->days} days)"
        );

        $this->assertNotEquals(
            $subscription->proximo_pagamento,
            $updatedSub->proximo_pagamento,
            'Next payment date should be updated'
        );

        $accessCheck = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertTrue($accessCheck['canAccess']);

        $this->seeInDatabase('subscriptions', [
            'id' => $subscriptionId,
            'status' => 'ACTIVE',
        ]);
    }
}
