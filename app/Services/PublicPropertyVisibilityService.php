<?php

namespace App\Services;

use App\Models\PaymentTransactionModel;

/** Regra única para qualquer exposição pública de imóveis. */
class PublicPropertyVisibilityService
{
    public function apply($builder, string $propertyAlias = 'properties')
    {
        $builder
            ->where("{$propertyAlias}.status", 'ACTIVE')
            ->where("{$propertyAlias}.deleted_at IS NULL", null, false)
            ->where(
                "{$propertyAlias}.account_id IN (SELECT id FROM accounts WHERE deleted_at IS NULL)",
                null,
                false
            );

        $blockedAccountIds = (new PaymentTransactionModel())->getOverdueAccountIdsCached(3);
        if ($blockedAccountIds !== []) {
            $builder->whereNotIn("{$propertyAlias}.account_id", $blockedAccountIds);
        }

        return $builder;
    }

    public function isVisible(int $propertyId): bool
    {
        if ($propertyId <= 0) {
            return false;
        }

        $builder = \Config\Database::connect()->table('properties');
        $this->apply($builder);

        return $builder->where('properties.id', $propertyId)->countAllResults() > 0;
    }

    public static function invalidateCaches(): void
    {
        $cache = cache();

        foreach ([
            'home_featured',
            'home_sponsored_pool',
            'home_filter_options',
            'search_filter_options',
            'overdue_account_ids_3',
        ] as $key) {
            $cache->delete($key);
        }

        $cache->deleteMatching('public_map_pins_*');
    }
}
