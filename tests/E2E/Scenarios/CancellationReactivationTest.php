<?php

namespace Tests\E2E\Scenarios;

use Tests\E2E\SubscriptionE2EBase;

/**
 * Scenario 8: Cancelamento e reativação
 */
class CancellationReactivationTest extends SubscriptionE2EBase
{
    public function testCancellationThenReactivation()
    {
        $testData = $this->createE2ETestAccount('persona_8_cancel_reactiv');
        $accountId = $testData['accountId'];
        $userId = $testData['userId'];

        $this->verifyAccountKYC($accountId, withFacial: true);

        $planId = $this->ensurePlan();
        $subModel = model('App\\Models\\SubscriptionModel');

        $firstSub = $this->createSubscription($accountId, $planId, 'MONTHLY');
        $firstSubId = $firstSub['subscriptionId'];

        $this->assertSubscriptionActive($firstSubId);

        // Cancelar
        $subModel->update($firstSubId, ['status' => 'CANCELLED']);

        $accessAfterCancel = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertFalse($accessAfterCancel['canAccess']);

        // Reativar com nova assinatura
        $newSub = $this->createSubscription($accountId, $planId, 'MONTHLY');
        $newSubId = $newSub['subscriptionId'];

        $this->assertNotEquals($firstSubId, $newSubId);
        $this->assertSubscriptionActive($newSubId);

        $accessAfterReactivate = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertTrue($accessAfterReactivate['canAccess']);

        $this->seeInDatabase('subscriptions', [
            'id' => $firstSubId,
            'status' => 'CANCELLED',
        ]);

        $this->seeInDatabase('subscriptions', [
            'id' => $newSubId,
            'status' => 'ACTIVE',
        ]);
    }

    private function ensurePlan(): int
    {
        $planModel = model('App\\Models\\PlanModel');

        return (int) $planModel->insert([
            'chave' => 'PLAN_REACT_' . uniqid(),
            'nome' => 'Plan Reactivation',
            'preco_mensal' => 129.90,
            'carencia_dias' => 0,
            'ativo' => true,
        ]);
    }
}
