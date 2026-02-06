<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class Account extends Entity
{
    protected $datamap = [];
    protected $dates   = ['created_at', 'updated_at', 'deleted_at'];
    protected $casts   = [
        'whatsapp_hub_config' => 'json-array',
        'whatsapp_messages_config' => 'json-array'
    ];
}
