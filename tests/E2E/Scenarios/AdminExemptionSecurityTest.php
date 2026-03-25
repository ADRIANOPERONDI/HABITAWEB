<?php

namespace Tests\E2E\Scenarios;

use Tests\E2E\SubscriptionE2EBase;

/**
 * Cenários de admin exemption e segurança operacional.
 */
class AdminExemptionSecurityTest extends SubscriptionE2EBase
{
    public function testSuperAdminCanAccessWithoutKycAndSubscription()
    {
        $testData = $this->createE2ETestAccount('persona_1_initial');
        $userId = $testData['userId'];

        $this->promoteUserToSuperAdmin($userId);

        $access = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertTrue($access['canAccess']);
        $this->assertEquals(200, $access['statusCode']);
    }

    public function testNonAdminBlockedWithoutKycAndSubscription()
    {
        $testData = $this->createE2ETestAccount('persona_2_renewal');
        $userId = $testData['userId'];

        $access = $this->checkUserAccess($userId, '/admin/dashboard');
        $this->assertFalse($access['canAccess']);
        $this->assertEquals(302, $access['statusCode']);
    }

    public function testCouponRestrictedToAccountCannotBeUsedByAnotherAccount()
    {
        $accountA = $this->createE2ETestAccount('persona_3_failed_recovery');
        $accountB = $this->createE2ETestAccount('persona_4_grace_expired');

        $couponModel = model('App\\Models\\CouponModel');
        $code = 'ACCOUNT_LOCK_' . uniqid();

        $couponId = (int) $couponModel->insert([
            'account_id' => $accountA['accountId'],
            'code' => $code,
            'description' => 'Uso restrito por conta',
            'discount_type' => 'percent',
            'discount_value' => 20,
            'is_active' => true,
        ]);

        $this->assertGreaterThan(0, $couponId);

        $validForOwner = $couponModel->getValidCoupon($code, $accountA['accountId']);
        $this->assertNotNull($validForOwner);

        $invalidForOther = $couponModel->getValidCoupon($code, $accountB['accountId']);
        $this->assertNull($invalidForOther);
    }

    public function testWebhookReplayDoesNotCreateExtraSubscriptionRows()
    {
        $testData = $this->createE2ETestAccount('persona_5_plan_grace');
        $accountId = $testData['accountId'];

        $this->verifyAccountKYC($accountId, withFacial: true);

        $planModel = model('App\\Models\\PlanModel');
        $planId = (int) $planModel->insert([
            'chave' => 'REPLAY_PLAN_' . uniqid(),
            'nome' => 'Replay Plan',
            'preco_mensal' => 120.00,
            'carencia_dias' => 0,
            'ativo' => true,
        ]);

        $subData = $this->createSubscription($accountId, $planId, 'MONTHLY');
        $subscriptionId = $subData['subscriptionId'];

        $this->simulateAsaasWebhook('PAYMENT_CONFIRMED', [
            'id' => 'pay_replay_fixed',
            'subscription' => $subscriptionId,
            'status' => 'CONFIRMED',
            'value' => 120.00,
        ]);

        $this->simulateAsaasWebhook('PAYMENT_CONFIRMED', [
            'id' => 'pay_replay_fixed',
            'subscription' => $subscriptionId,
            'status' => 'CONFIRMED',
            'value' => 120.00,
        ]);

        $db = \Config\Database::connect();
        $subRows = $db->table('subscriptions')->where('account_id', $accountId)->countAllResults();

        $this->assertEquals(1, $subRows);
        $this->assertSubscriptionActive($subscriptionId);
    }

    public function testOwnershipGuardForSubscriptionQuery()
    {
        $owner = $this->createE2ETestAccount('persona_6_coupon_grace');
        $other = $this->createE2ETestAccount('persona_7_upgrade');

        $this->verifyAccountKYC($owner['accountId'], withFacial: true);

        $planModel = model('App\\Models\\PlanModel');
        $planId = (int) $planModel->insert([
            'chave' => 'OWN_PLAN_' . uniqid(),
            'nome' => 'Ownership Plan',
            'preco_mensal' => 99.90,
            'carencia_dias' => 0,
            'ativo' => true,
        ]);

        $subData = $this->createSubscription($owner['accountId'], $planId, 'MONTHLY');
        $subscriptionId = $subData['subscriptionId'];

        $db = \Config\Database::connect();
        $forOwner = $db->table('subscriptions')
            ->where('id', $subscriptionId)
            ->where('account_id', $owner['accountId'])
            ->get()
            ->getRow();

        $forOther = $db->table('subscriptions')
            ->where('id', $subscriptionId)
            ->where('account_id', $other['accountId'])
            ->get()
            ->getRow();

        $this->assertNotNull($forOwner);
        $this->assertNull($forOther);
    }
}
