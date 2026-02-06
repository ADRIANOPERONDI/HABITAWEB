<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class Client extends Entity
{
    protected $datamap = [];
    protected $dates   = ['created_at', 'updated_at', 'deleted_at'];
    protected $casts   = [
        'id'         => 'integer',
        'account_id' => 'integer',
    ];
}
