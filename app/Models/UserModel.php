<?php

namespace App\Models;

use CodeIgniter\Shield\Models\UserModel as ShieldUserModel;

class UserModel extends ShieldUserModel
{
    protected $initializeSoftDeletes = true;
    protected $returnType           = \App\Entities\User::class;

    protected $allowedFields = [
        'username', 'nome', 'status', 'status_message', 'active', 'last_active', 'deleted_at', 'account_id',
        // Exibição pública opcional na página da imobiliária (Fase 5).
        'publico', 'cargo', 'foto', 'bio', 'creci',
    ];

    // Postgres devolve booleano como 'f'/'t', e a string 'f' é truthy em PHP
    // — sem este cast, todo corretor apareceria como público na vitrine.
    protected array $casts = [
        'publico' => 'boolean',
    ];
}
