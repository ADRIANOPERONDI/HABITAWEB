<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class PropertyMedia extends Entity
{
    protected $datamap = [];
    protected $dates   = ['created_at', 'updated_at', 'deleted_at'];
    protected $casts   = [
        'principal' => 'boolean'  // PostgreSQL retorna 't'/'f', forçar boolean
    ];

    /**
     * PostgreSQL retorna booleans como 't'/'f'. O cast nativo do CI4 (bool)
     * avalia 'f' como true. Sobrescrevemos para garantir o valor correto.
     */
    protected function castAs($value, string $attribute, string $method = 'get')
    {
        if ($attribute === 'boolean') {
            if ($value === 'f' || $value === 'false' || $value === 0 || $value === '0') {
                return false;
            }
            if ($value === 't' || $value === 'true' || $value === 1 || $value === '1') {
                return true;
            }
        }

        return parent::castAs($value, $attribute, $method);
    }
}
