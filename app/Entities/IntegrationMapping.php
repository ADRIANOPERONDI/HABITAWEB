<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class IntegrationMapping extends Entity
{
    protected $casts = [
        'id'                     => 'integer',
        'account_integration_id' => 'integer',
        'is_confirmed'           => 'boolean',
    ];

    /** Mapeamento que efetivamente leva um valor para alguma coluna. */
    public function isMapped(): bool
    {
        return ! empty($this->attributes['target_field']) || ! empty($this->attributes['target_value']);
    }
}
