<?php

namespace App\Models;

use CodeIgniter\Shield\Models\UserModel as ShieldUserModel;

class UserModel extends ShieldUserModel
{
    protected $initializeSoftDeletes = true;
    protected $returnType           = \App\Entities\User::class;

    protected $allowedFields = [
        'username', 'nome', 'status', 'status_message', 'active', 'last_active', 'deleted_at', 'account_id'
    ];
}
