<?php

namespace Tests\Feature;

use App\Models\CouponModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre App\Models\CouponModel::getValidCoupon() e registerUsage() — o fluxo mais
 * unit-testável do sistema de pagamento (sem chamada a gateway externo). O ponto
 * central é o UPDATE atômico condicional em registerUsage(), que garante que
 * used_count nunca ultrapasse max_uses mesmo sob checkouts concorrentes.
 */
final class CouponTest extends HabitawebTestCase
{
    private function insertCoupon(array $overrides = []): object
    {
        $model = new CouponModel();
        $model->insert(array_merge([
            'code'           => 'TESTE' . bin2hex(random_bytes(3)),
            'discount_type'  => 'percent',
            'discount_value' => 10,
            'is_active'      => true,
        ], $overrides));

        return $model->find($model->getInsertID());
    }

    public function testValidCouponIsReturned(): void
    {
        $coupon = $this->insertCoupon();

        $found = (new CouponModel())->getValidCoupon($coupon->code);

        $this->assertNotNull($found);
        $this->assertSame($coupon->code, $found->code);
    }

    public function testInactiveCouponIsRejected(): void
    {
        $coupon = $this->insertCoupon(['is_active' => false]);

        $this->assertNull((new CouponModel())->getValidCoupon($coupon->code));
    }

    public function testExpiredCouponIsRejected(): void
    {
        $coupon = $this->insertCoupon(['valid_until' => date('Y-m-d H:i:s', strtotime('-1 day'))]);

        $this->assertNull((new CouponModel())->getValidCoupon($coupon->code));
    }

    public function testNotYetValidCouponIsRejected(): void
    {
        $coupon = $this->insertCoupon(['valid_from' => date('Y-m-d H:i:s', strtotime('+1 day'))]);

        $this->assertNull((new CouponModel())->getValidCoupon($coupon->code));
    }

    public function testAccountRestrictedCouponRejectsOtherAccounts(): void
    {
        $tenant = (new TenantFactory())->create();
        $coupon = $this->insertCoupon(['account_id' => $tenant['account']->id]);

        $this->assertNull((new CouponModel())->getValidCoupon($coupon->code, $tenant['account']->id + 999999));
        $this->assertNull((new CouponModel())->getValidCoupon($coupon->code)); // sem account_id nenhum
        $this->assertNotNull((new CouponModel())->getValidCoupon($coupon->code, $tenant['account']->id));
    }

    public function testCouponAtUsageLimitIsRejected(): void
    {
        $coupon = $this->insertCoupon(['max_uses' => 1, 'used_count' => 1]);

        $this->assertNull((new CouponModel())->getValidCoupon($coupon->code));
    }

    public function testRegisterUsageIncrementsCountAndLogsUsage(): void
    {
        $tenant = (new TenantFactory())->create();
        $coupon = $this->insertCoupon(['max_uses' => 5, 'used_count' => 0]);

        $ok = (new CouponModel())->registerUsage($coupon->id, $tenant['account']->id, null, 15.50);

        $this->assertTrue($ok);
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'used_count' => 1]);
        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id'  => $coupon->id,
            'account_id' => $tenant['account']->id,
        ]);
    }

    /**
     * O núcleo do que a auditoria pediu para blindar: o UPDATE condicional em
     * registerUsage() nunca deixa used_count passar de max_uses, mesmo chamando
     * em sequência rápida (aqui simulando concorrência com chamadas sequenciais
     * já no limite, já que um teste single-threaded não reproduz a corrida real,
     * mas garante a condição de contorno que a query SQL impõe).
     */
    public function testRegisterUsageFailsAtomicallyWhenLimitAlreadyReached(): void
    {
        $tenant = (new TenantFactory())->create();
        $coupon = $this->insertCoupon(['max_uses' => 1, 'used_count' => 1]);

        $ok = (new CouponModel())->registerUsage($coupon->id, $tenant['account']->id, null, 10.00);

        $this->assertFalse($ok);
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'used_count' => 1]);
        $this->assertDatabaseMissing('coupon_usages', ['coupon_id' => $coupon->id]);
    }

    public function testUnlimitedCouponHasNoUsageCap(): void
    {
        $coupon = $this->insertCoupon(['max_uses' => null, 'used_count' => 500]);

        $this->assertNotNull((new CouponModel())->getValidCoupon($coupon->code));
    }
}
