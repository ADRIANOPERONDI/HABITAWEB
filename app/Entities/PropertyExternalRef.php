<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class PropertyExternalRef extends Entity
{
    protected $casts = [
        'id'          => 'integer',
        'property_id' => 'integer',
        'account_id'  => 'integer',
    ];

    protected $dates = ['external_updated_at', 'last_synced_at', 'created_at', 'updated_at'];
}
