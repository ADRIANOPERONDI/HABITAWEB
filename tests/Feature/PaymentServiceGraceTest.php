<?php

namespace Tests\Feature;

use App\Services\PaymentService;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre App\Services\PaymentService::determineInitialSubscriptionStatus() — a
 * decisão de status inicial que liga plans.carencia_dias ao acesso imediato no
 * checkout (Cenário 5 da auditoria). Sem gateway/rede: testa a função pura que
 * initializeSubscription()/initiateTokenizationPayment() usam de verdade, não uma
 * reimplementação. O caminho completo (gateway real + due_date deslocado) é
 * coberto por Tests\E2E\SubscriptionSandboxTest (grupo asaas-sandbox).
 */
final class PaymentServiceGraceTest extends HabitawebTestCase
{
    public function testGraceDaysGreaterThanZeroActivatesImmediately(): void
    {
        $service = new PaymentService();

        $this->assertSame('ACTIVE', $service->determineInitialSubscriptionStatus(3, 'PENDING'));
        $this->assertSame('ACTIVE', $service->determineInitialSubscriptionStatus(90, 'PENDING'));
    }

    public function testNoGraceDaysKeepsFallbackStatus(): void
    {
        $service = new PaymentService();

        $this->assertSame('PENDING', $service->determineInitialSubscriptionStatus(0, 'PENDING'));
        $this->assertSame('ACTIVE', $service->determineInitialSubscriptionStatus(0, 'ACTIVE'));
    }

    public function testNegativeGraceDaysIsTreatedAsNoGrace(): void
    {
        $service = new PaymentService();

        $this->assertSame('PENDING', $service->determineInitialSubscriptionStatus(-1, 'PENDING'));
    }
}
