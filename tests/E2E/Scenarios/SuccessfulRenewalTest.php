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

        // App\Services\WebhookService::calculateNextBillingDate() só soma +1 mês
        // quando a data atual JÁ EXPIROU (estritamente antes de hoje); se
        // data_fim/next_billing_date é hoje ou futuro, ele trata isso como "já é
        // a data correta" e não avança nada. createSubscription() deixa data_fim
        // 30 dias à frente (correto para a 1ª cobrança) — para testar uma
        // RENOVAÇÃO de verdade, o ciclo anterior precisa já ter vencido (ontem)
        // antes do webhook de confirmação chegar.
        $subModel = model('App\Models\SubscriptionModel');
        $expiredEndDate = date('Y-m-d', strtotime('-1 day'));
        $subModel->update($subscriptionId, [
            'data_fim' => $expiredEndDate,
            'next_billing_date' => null,
        ]);
        $originalEndDate = $expiredEndDate;

        $this->assertSubscriptionActive($subscriptionId);

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

        // App\Services\WebhookService::handlePaymentConfirmed() atualiza data_fim
        // e next_billing_date, mas NUNCA proximo_pagamento (campo legado, mantido
        // só para compatibilidade — não é a fonte de verdade pós-webhook).
        $this->assertNotEquals(
            $subscription->data_fim,
            $updatedSub->next_billing_date,
            'Next billing date should be updated'
        );

        $accessCheck = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertTrue($accessCheck['canAccess']);

        $this->seeInDatabase('subscriptions', [
            'id' => $subscriptionId,
            'status' => 'ACTIVE',
        ]);
    }
}
