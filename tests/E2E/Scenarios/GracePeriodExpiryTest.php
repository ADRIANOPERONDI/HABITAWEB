<?php

namespace Tests\E2E\Scenarios;

use Tests\E2E\SubscriptionE2EBase;

/**
 * Scenario 4: Expiração da carência (bloqueio após atraso)
 */
class GracePeriodExpiryTest extends SubscriptionE2EBase
{
    public function testAccessAllowedWithinGraceWindow()
    {
        $testData = $this->createE2ETestAccount('persona_4_grace_expired');
        $accountId = $testData['accountId'];
        $userId = $testData['userId'];

        $this->verifyAccountKYC($accountId, withFacial: true);

        $planId = $this->ensurePlan('PLAN_GRACE_BASE', 149.90, 0);
        $subData = $this->createSubscription($accountId, $planId, 'MONTHLY');
        $subscriptionId = $subData['subscriptionId'];

        // Dentro da janela de carência operacional (simulada): mantém ACTIVE
        model('App\\Models\\SubscriptionModel')->update($subscriptionId, [
            'proximo_pagamento' => (new \DateTime())->modify('-2 days')->format('Y-m-d'),
            'status' => 'ACTIVE',
        ]);

        $access = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertTrue($access['canAccess']);
    }

    public function testAccessBlockedAfterGraceExpiry()
    {
        $testData = $this->createE2ETestAccount('persona_4_grace_expired');
        $accountId = $testData['accountId'];
        $userId = $testData['userId'];

        $this->verifyAccountKYC($accountId, withFacial: true);

        $planId = $this->ensurePlan('PLAN_GRACE_BLOCK', 149.90, 0);
        $subData = $this->createSubscription($accountId, $planId, 'MONTHLY');
        $subscriptionId = $subData['subscriptionId'];

        // Após expiração da carência: subscription suspensa
        model('App\\Models\\SubscriptionModel')->update($subscriptionId, [
            'proximo_pagamento' => (new \DateTime())->modify('-5 days')->format('Y-m-d'),
            'status' => 'SUSPENDED',
        ]);

        $access = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertFalse($access['canAccess']);
    }

    private function ensurePlan(string $prefix, float $price, int $carencia): int
    {
        $planModel = model('App\\Models\\PlanModel');
        $key = $prefix . '_' . uniqid();

        return (int) $planModel->insert([
            'chave' => $key,
            'nome' => $prefix,
            'preco_mensal' => $price,
            'carencia_dias' => $carencia,
            'ativo' => true,
        ]);
    }
}
