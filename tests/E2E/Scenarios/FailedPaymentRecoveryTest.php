<?php

namespace Tests\E2E\Scenarios;

use Tests\E2E\SubscriptionE2EBase;

/**
 * Scenario 3: Falha de pagamento + recuperação
 *
 * RESOLVIDO (decisão de produto: era um gap de segurança, corrigido). A investigação
 * inicial havia deixado estes 2 testes como Incomplete porque revelavam que uma
 * fatura vencida podia não bloquear o painel. Correções aplicadas:
 *
 * 1. O evento Asaas correto para "pagamento não recebido" é PAYMENT_OVERDUE (o antigo
 *    PAYMENT_FAILED mapeia para handleCancellation e nem localizava a assinatura).
 *    Os testes agora usam PAYMENT_OVERDUE. handlePaymentOverdue() move a assinatura
 *    para OVERDUE e a conta para SUSPENDED — e o AdminAuth bloqueia porque a conta
 *    deixa de ter assinatura ACTIVE.
 *
 * 2. O gap real: PaymentTransactionModel::isAccountBlockedByOverdue() ignorava
 *    transações 'OVERDUE' (só via 'PENDING'/'AWAITING_PAYMENT'), então uma fatura
 *    vencida há 3+ dias com a assinatura ainda ACTIVE não bloqueava. Corrigido:
 *    'OVERDUE' entrou no filtro (ver o Model). testPaymentFailureGracePeriodExpiry
 *    exercita exatamente esse caminho e falha se o 'OVERDUE' for removido do filtro.
 */
class FailedPaymentRecoveryTest extends SubscriptionE2EBase
{
    /**
     * Fluxo real de inadimplência e recuperação, exercendo o código de produção
     * (webhook assinado -> WebhookService -> AdminAuth), com o evento Asaas CORRETO
     * para "pagamento não recebido": PAYMENT_OVERDUE (o antigo PAYMENT_FAILED mapeia
     * para handleCancellation, um evento diferente, e nem localizava a assinatura).
     */
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

        // 1. Pagamento vence -> webhook PAYMENT_OVERDUE
        $webhookResult = $this->simulateAsaasWebhook('PAYMENT_OVERDUE', [
            'id' => 'pay_overdue_' . time(),
            'subscription' => $subscriptionId,
            'status' => 'OVERDUE',
            'value' => (float) ($plan->preco_mensal ?? 199.90),
        ]);

        $this->assertEquals(200, $webhookResult['statusCode']);

        // WebhookService::handlePaymentOverdue: assinatura -> OVERDUE, conta -> SUSPENDED.
        $subModel = model('App\Models\SubscriptionModel');
        $overdueSub = $subModel->find($subscriptionId);
        $this->assertEquals('OVERDUE', $overdueSub->status,
            'Assinatura deve ficar OVERDUE após vencimento confirmado pelo gateway'
        );
        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'status' => 'SUSPENDED']);

        // 2. Painel bloqueado (AdminAuth: sem assinatura ACTIVE).
        $accessCheck = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertFalse($accessCheck['canAccess'],
            'Conta com assinatura vencida não deve acessar o painel'
        );

        // 3. Pagamento recuperado -> webhook PAYMENT_CONFIRMED
        $retryWebhookResult = $this->simulateAsaasWebhook('PAYMENT_CONFIRMED', [
            'id' => 'pay_retry_' . time(),
            'subscription' => $subscriptionId,
            'status' => 'CONFIRMED',
            'value' => (float) ($plan->preco_mensal ?? 199.90),
            'paymentDate' => date('Y-m-d'),
        ]);

        $this->assertEquals(200, $retryWebhookResult['statusCode']);

        // handlePaymentConfirmed: transação -> CONFIRMED, assinatura -> ACTIVE, conta -> ACTIVE.
        $recoveredSub = $subModel->find($subscriptionId);
        $this->assertEquals('ACTIVE', $recoveredSub->status);

        // 4. Acesso restaurado.
        $accessRestored = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertTrue($accessRestored['canAccess'],
            'Após recuperação do pagamento, o acesso ao painel deve voltar'
        );
    }

    /**
     * Exercita especificamente o gate PaymentTransactionModel::isAccountBlockedByOverdue():
     * uma fatura OVERDUE há mais de 3 dias (grace expirada) bloqueia o painel MESMO com
     * a assinatura ainda ACTIVE. É exatamente a lacuna corrigida na auditoria — antes,
     * transações OVERDUE eram ignoradas por esse filtro (só PENDING/AWAITING_PAYMENT
     * contavam), então este cenário NÃO bloqueava.
     */
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

        // A fatura da assinatura fica OVERDUE e vencida há 4 dias (fora da carência de 3),
        // enquanto a assinatura permanece ACTIVE. Sem o fix do isAccountBlockedByOverdue,
        // este estado passaria batido pelo AdminAuth.
        $txModel = model('App\Models\PaymentTransactionModel');
        $tx = $txModel->where('subscription_id', $subscriptionId)->first();
        $this->assertNotNull($tx, 'A transação da assinatura deveria existir (createSubscription).');
        $txModel->update($tx['id'], [
            'status'   => 'OVERDUE',
            'due_date' => (new \DateTime())->modify('-4 days')->format('Y-m-d'),
        ]);

        // Sanidade: a assinatura NÃO foi tocada, segue ACTIVE — o bloqueio vem só do overdue.
        $this->assertDatabaseHas('subscriptions', ['id' => $subscriptionId, 'status' => 'ACTIVE']);

        $accessCheck = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertFalse($accessCheck['canAccess'],
            'Fatura vencida há mais de 3 dias deve bloquear o painel (isAccountBlockedByOverdue).'
        );
    }
}
