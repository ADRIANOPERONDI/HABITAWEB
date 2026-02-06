<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class PropertyFavorite extends Entity
{
    protected $datamap = [];
    protected $dates   = ['created_at'];
    protected $casts   = [
        'id'          => 'integer',
        'user_id'     => 'integer',
        'property_id' => 'integer',
    ];
}
