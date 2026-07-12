<?php

namespace Tests\E2E\Scenarios;

use App\Models\PaymentTransactionModel;
use Tests\E2E\SubscriptionE2EBase;
use Tests\Support\Factories\TenantFactory;

/**
 * Scenario 5: Contratação com carência no plano
 *
 * RESOLVIDO (decisão de produto, 2026-07-10): carência de plano libera o painel
 * imediatamente na contratação — sem esperar a primeira fatura ser paga. A fatura
 * só vence ao fim da carência (plans.carencia_dias). Se não pagar até lá (+ os 3
 * dias de tolerância padrão do AdminAuth), bloqueia; pagando, segue com cobrança
 * mensal normal daí em diante.
 *
 * Implementado em PaymentService::determineInitialSubscriptionStatus(), chamado
 * por initializeSubscription() e initiateTokenizationPayment(): a assinatura
 * ativa de imediato quando o plano tem carencia_dias > 0, com o due_date da
 * primeira fatura deslocado para o fim da carência (nextDueDate/finalGraceDays,
 * já calculado antes disso em ambos os métodos). Sem carência (carencia_dias = 0,
 * o caso comum fora de planos promocionais), mantém o comportamento anterior —
 * PENDING até o webhook confirmar o primeiro pagamento — preservando o fix
 * anti-fraude original (Asaas marca PIX/Boleto como ACTIVE no gateway mesmo sem
 * pagamento; sem carência isso continua sendo sobrescrito localmente).
 *
 * Este teste cobre o caminho fim-a-fim via App\Filters\AdminAuth (o gate real),
 * montando em DB o estado que o checkout de produção produziria. A decisão pura
 * (graceDays -> status) é coberta sem rede em Tests\Feature\PaymentServiceGraceTest;
 * o caminho completo com gateway real (due_date de fato deslocado na Asaas) fica
 * em Tests\E2E\SubscriptionSandboxTest (grupo asaas-sandbox).
 */
class PlanGracePeriodTest extends SubscriptionE2EBase
{
    public function testAccessGrantedDuringGraceThenBlockedWhenUnpaidAtDueDate(): void
    {
        $tenant = (new TenantFactory())->create();
        $accountId = $tenant['account']->id;
        $userId = $tenant['user']->id;

        // Remove a assinatura ACTIVE padrão da factory: quem deve provar o acesso
        // aqui é exclusivamente a assinatura de carência montada abaixo.
        \Config\Database::connect()->table('subscriptions')
            ->where('account_id', $accountId)
            ->delete();

        $planId = $this->createGracePlan(90); // 3 meses de carência, como no exemplo do produto.
        $subscriptionId = $this->createGraceSubscription($accountId, $planId, 90);

        // 1. Dentro da carência (fatura só vence daqui a 90 dias): acesso liberado
        // mesmo sem nenhum pagamento confirmado ainda — exatamente o que
        // determineInitialSubscriptionStatus() decide de verdade na contratação.
        $accessDuringGrace = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertTrue($accessDuringGrace['canAccess'],
            'Conta dentro da carência do plano deve acessar o painel mesmo sem fatura paga.'
        );

        // 2. A fatura vence e passa dos 3 dias de tolerância do AdminAuth sem
        // pagamento: bloqueia via isAccountBlockedByOverdue (mesma trava do Cenário 3).
        $txModel = model('App\Models\PaymentTransactionModel');
        $txModel->where('subscription_id', $subscriptionId)->set([
            'due_date' => (new \DateTime())->modify('-4 days')->format('Y-m-d'),
        ])->update();

        $accessAfterDueDate = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertFalse($accessAfterDueDate['canAccess'],
            'Fatura vencida há mais de 3 dias sem pagamento deve bloquear, mesmo tendo havido carência.'
        );

        // 3. Paga a fatura -> segue com cobrança normal daqui pra frente.
        $retryWebhookResult = $this->simulateAsaasWebhook('PAYMENT_CONFIRMED', [
            'id' => 'pay_grace_ok_' . uniqid(),
            'subscription' => $subscriptionId,
            'status' => 'CONFIRMED',
            'value' => 199.90,
        ]);
        $this->assertEquals(200, $retryWebhookResult['statusCode']);

        $subModel = model('App\Models\SubscriptionModel');
        $this->assertEquals('ACTIVE', $subModel->find($subscriptionId)->status);

        $accessAfterPayment = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertTrue($accessAfterPayment['canAccess'],
            'Após pagar a fatura, o acesso deve voltar/continuar liberado.'
        );
    }

    /**
     * Contraprova: sem carência (carencia_dias = 0, o padrão fora de planos
     * promocionais), a assinatura continua PENDING até o pagamento confirmar — o
     * fix anti-fraude original não foi enfraquecido para esse caso.
     */
    public function testWithoutGraceSubscriptionStaysPendingUntilPayment(): void
    {
        $paymentService = new \App\Services\PaymentService();
        $this->assertSame(
            'PENDING',
            $paymentService->determineInitialSubscriptionStatus(0, 'PENDING'),
            'Sem carência, a assinatura PIX/Boleto/tokenização deve continuar PENDING até o pagamento.'
        );

        $tenant = (new TenantFactory())->create();
        $accountId = $tenant['account']->id;
        $userId = $tenant['user']->id;

        \Config\Database::connect()->table('subscriptions')
            ->where('account_id', $accountId)
            ->delete();

        $planId = $this->createGracePlan(0);
        $this->createGraceSubscription($accountId, $planId, 0, status: 'PENDING');

        $access = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertFalse($access['canAccess'],
            'Sem carência, assinatura PENDING não deve liberar o painel antes do pagamento.'
        );
    }

    private function createGracePlan(int $carenciaDias): int
    {
        $planModel = model('App\Models\PlanModel');

        return (int) $planModel->insert([
            'chave' => 'PLAN_GRACE_' . uniqid(),
            'nome' => 'Plan Grace ' . $carenciaDias . '_' . uniqid(),
            'preco_mensal' => 199.90,
            'carencia_dias' => $carenciaDias,
            'ativo' => true,
        ]);
    }

    /**
     * Monta em DB exatamente o estado que PaymentService::initializeSubscription()
     * produz de verdade para um plano com carência: assinatura no status decidido
     * por determineInitialSubscriptionStatus(), e a transação da primeira fatura
     * com due_date deslocado para o fim da carência (hoje + carenciaDias).
     */
    private function createGraceSubscription(int $accountId, int $planId, int $carenciaDias, string $status = 'ACTIVE'): int
    {
        $subscriptionModel = model('App\Models\SubscriptionModel');
        $asaasSubscriptionId = 'sub_grace_e2e_' . bin2hex(random_bytes(6));

        $subscriptionId = $subscriptionModel->insert([
            'account_id' => $accountId,
            'plan_id' => $planId,
            'status' => $status,
            'billing_cycle' => 'MONTHLY',
            'data_inicio' => date('Y-m-d'),
            'data_fim' => (new \DateTime())->modify('+1 year')->format('Y-m-d'),
            'proximo_pagamento' => (new \DateTime())->modify("+{$carenciaDias} days")->format('Y-m-d'),
            'asaas_subscription_id' => $asaasSubscriptionId,
        ]);

        (new PaymentTransactionModel())->insert([
            'subscription_id' => $subscriptionId,
            'account_id' => $accountId,
            'gateway' => 'asaas',
            'gateway_subscription_id' => $asaasSubscriptionId,
            'method' => 'PIX',
            'amount' => 199.90,
            'status' => 'PENDING',
            'type' => 'SUBSCRIPTION',
            'due_date' => (new \DateTime())->modify("+{$carenciaDias} days")->format('Y-m-d'),
        ]);

        return $subscriptionId;
    }
}
