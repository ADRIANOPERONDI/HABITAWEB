<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class PropertyExternalRef extends Entity
{
    protected $casts = [
        'id'          => 'integer',
        // Nullable: um vinculo com falha de validacao (upsertProperty) nao
        // tem imovel nenhum atras dele — cast 'integer' puro transformaria
        // esse NULL em 0, um id de imovel que nao existe.
        'property_id' => '?integer',
        'account_id'  => 'integer',
    ];

    protected $dates = ['external_updated_at', 'last_synced_at', 'created_at', 'updated_at'];
}
