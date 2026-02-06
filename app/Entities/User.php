<?php

namespace App\Entities;

use CodeIgniter\Shield\Entities\User as ShieldUser;

class User extends ShieldUser
{
    /**
     * Retorna o nome real ou o username como fallback.
     */
    public function getDisplayName(): string
    {
        return !empty($this->attributes['nome'] ?? null) ? $this->attributes['nome'] : $this->username;
    }
}
