<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class Promotion extends Entity
{
    protected $datamap = [];
    protected $dates   = ['created_at', 'updated_at', 'data_inicio', 'data_fim'];
    protected $casts   = [
        'id'          => 'integer',
        'property_id' => 'integer',
        'ativo'       => 'boolean',
    ];
}
