<?php

namespace Tests\E2E\Scenarios;

use Tests\E2E\SubscriptionE2EBase;

/**
 * Scenario 6: Contratação com cupom de desconto + carência
 */
class CouponGracePeriodTest extends SubscriptionE2EBase
{
    public function testCouponGraceAndDiscountFlow()
    {
        $testData = $this->createE2ETestAccount('persona_6_coupon_grace');
        $accountId = $testData['accountId'];

        $this->verifyAccountKYC($accountId, withFacial: true);

        $planModel = model('App\\Models\\PlanModel');
        $couponModel = model('App\\Models\\CouponModel');

        $planId = (int) $planModel->insert([
            'chave' => 'PLAN_COUPON_BASE_' . uniqid(),
            'nome' => 'Plan Coupon Base',
            'preco_mensal' => 200.00,
            'carencia_dias' => 0,
            'ativo' => true,
        ]);

        $couponId = (int) $couponModel->insert([
            'code' => 'PROMO30_' . uniqid(),
            'description' => '50% + 30 dias carência',
            'discount_type' => 'percent',
            'discount_value' => 50,
            'max_uses' => 100,
            'used_count' => 0,
            'is_active' => true,
            'carencia_tipo' => 'dias',
            'carencia_valor' => 30,
        ]);

        $coupon = $couponModel->find($couponId);
        $this->assertNotNull($coupon);

        // Regra: pode aplicar cupom com carência quando plano não possui carência
        $canApply = $couponModel->canBeAppliedWithPlanGrace($coupon, $planId);
        $this->assertTrue($canApply['isValid']);

        // Simular uso do cupom
        $couponModel->registerUsage($couponId, $accountId, null, 100.00);

        $updatedCoupon = $couponModel->find($couponId);
        $this->assertEquals(1, (int) $updatedCoupon->used_count);

        // Assert log de uso
        $this->seeInDatabase('coupon_usages', [
            'coupon_id' => $couponId,
            'account_id' => $accountId,
        ]);
    }

    public function testCouponAndPlanGraceCannotStack()
    {
        $planModel = model('App\\Models\\PlanModel');
        $couponModel = model('App\\Models\\CouponModel');

        $planWithGraceId = (int) $planModel->insert([
            'chave' => 'PLAN_STACK_DENY_' . uniqid(),
            'nome' => 'Plan With Grace',
            'preco_mensal' => 180.00,
            'carencia_dias' => 30,
            'ativo' => true,
        ]);

        $couponId = (int) $couponModel->insert([
            'code' => 'STACK_DENY_' . uniqid(),
            'description' => 'Coupon with grace',
            'discount_type' => 'percent',
            'discount_value' => 30,
            'is_active' => true,
            'carencia_tipo' => 'dias',
            'carencia_valor' => 15,
        ]);

        $coupon = $couponModel->find($couponId);
        $result = $couponModel->canBeAppliedWithPlanGrace($coupon, $planWithGraceId);

        $this->assertFalse($result['isValid']);
    }
}
