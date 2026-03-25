<?php

namespace Tests\E2E\Scenarios;

use Tests\E2E\SubscriptionE2EBase;

/**
 * Scenario 5: Contratação com carência no plano
 */
class PlanGracePeriodTest extends SubscriptionE2EBase
{
    public function testPlanGracePeriodFlow()
    {
        $testData = $this->createE2ETestAccount('persona_5_plan_grace');
        $accountId = $testData['accountId'];
        $userId = $testData['userId'];

        $this->verifyAccountKYC($accountId, withFacial: true);

        $planId = $this->ensureGracePlan(30);
        $subData = $this->createSubscription($accountId, $planId, 'MONTHLY');
        $subscriptionId = $subData['subscriptionId'];

        // Simular início da carência: primeira cobrança no fim da janela
        model('App\\Models\\SubscriptionModel')->update($subscriptionId, [
            'data_inicio' => date('Y-m-d'),
            'proximo_pagamento' => (new \DateTime())->modify('+30 days')->format('Y-m-d'),
            'status' => 'ACTIVE',
        ]);

        // Durante carência: acesso permitido
        $accessDuringGrace = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertTrue($accessDuringGrace['canAccess']);

        // Sem cobrança registrada no dia de contratação
        $txCount = model('App\\Models\\PaymentTransactionModel')
            ->where('account_id', $accountId)
            ->countAllResults();
        $this->assertEquals(0, $txCount);

        // Dia 31 com falha de pagamento -> bloqueia
        $this->simulateAsaasWebhook('PAYMENT_FAILED', [
            'id' => 'pay_grace_fail_' . uniqid(),
            'subscription' => $subscriptionId,
            'status' => 'FAILED',
            'value' => 199.90,
        ]);

        $accessAfterGraceFail = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertFalse($accessAfterGraceFail['canAccess']);

        // Retry com sucesso -> desbloqueia
        $this->simulateAsaasWebhook('PAYMENT_CONFIRMED', [
            'id' => 'pay_grace_ok_' . uniqid(),
            'subscription' => $subscriptionId,
            'status' => 'CONFIRMED',
            'value' => 199.90,
        ]);

        $accessAfterRecovery = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertTrue($accessAfterRecovery['canAccess']);
    }

    private function ensureGracePlan(int $carenciaDias): int
    {
        $planModel = model('App\\Models\\PlanModel');

        return (int) $planModel->insert([
            'chave' => 'PLAN_GRACE_' . uniqid(),
            'nome' => 'Plan Grace ' . $carenciaDias,
            'preco_mensal' => 199.90,
            'carencia_dias' => $carenciaDias,
            'ativo' => true,
        ]);
    }
}
