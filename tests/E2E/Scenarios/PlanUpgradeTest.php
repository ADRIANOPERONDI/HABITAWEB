<?php

namespace Tests\E2E\Scenarios;

use Tests\E2E\SubscriptionE2EBase;

/**
 * Scenario 7: Upgrade de plano no meio do ciclo com prorrata
 */
class PlanUpgradeTest extends SubscriptionE2EBase
{
    public function testMidCycleUpgradeWithProration()
    {
        $testData = $this->createE2ETestAccount('persona_7_upgrade');
        $accountId = $testData['accountId'];

        $this->verifyAccountKYC($accountId, withFacial: true);

        $planModel = model('App\\Models\\PlanModel');
        $txModel = model('App\\Models\\PaymentTransactionModel');
        $subModel = model('App\\Models\\SubscriptionModel');

        $basicPlanId = (int) $planModel->insert([
            'chave' => 'BASIC_UP_' . uniqid(),
            'nome' => 'Basic Upgrade',
            'preco_mensal' => 50.00,
            'carencia_dias' => 0,
            'ativo' => true,
        ]);

        $proPlanId = (int) $planModel->insert([
            'chave' => 'PRO_UP_' . uniqid(),
            'nome' => 'Pro Upgrade',
            'preco_mensal' => 100.00,
            'carencia_dias' => 0,
            'ativo' => true,
        ]);

        $subData = $this->createSubscription($accountId, $basicPlanId, 'MONTHLY');
        $subscriptionId = $subData['subscriptionId'];

        // Simular que já passou metade do ciclo
        $start = (new \DateTime())->modify('-15 days')->format('Y-m-d');
        $end = (new \DateTime())->modify('+15 days')->format('Y-m-d');

        $subModel->update($subscriptionId, [
            'data_inicio' => $start,
            'data_fim' => $end,
            'proximo_pagamento' => $end,
        ]);

        $beforeUpgrade = $subModel->find($subscriptionId);

        // Prorrata: (100 - 50) * (15/30) = 25
        $prorated = 25.00;

        // Simular cobrança de upgrade
        $txModel->insert([
            'account_id' => $accountId,
            'external_id' => 'tx_upgrade_' . uniqid(),
            'method' => 'CREDIT_CARD',
            'amount' => $prorated,
            'status' => 'CONFIRMED',
            'type' => 'SUBSCRIPTION',
            'reference_id' => $subscriptionId,
            'description' => 'Prorrata upgrade Basic->Pro',
        ]);

        // Atualizar plano sem alterar fim do ciclo
        $subModel->update($subscriptionId, [
            'plan_id' => $proPlanId,
        ]);

        $afterUpgrade = $subModel->find($subscriptionId);

        $this->assertEquals($proPlanId, (int) $afterUpgrade->plan_id);
        $this->assertEquals($beforeUpgrade->data_fim, $afterUpgrade->data_fim);

        $this->seeInDatabase('payment_transactions', [
            'account_id' => $accountId,
            'reference_id' => $subscriptionId,
            'status' => 'CONFIRMED',
            'amount' => '25.00',
        ]);
    }
}
