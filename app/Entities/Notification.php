<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class Notification extends Entity
{
    protected $dates   = ['created_at', 'updated_at', 'deleted_at', 'read_at'];
    protected $casts   = [
        'id' => 'integer',
        'user_id' => 'integer',
        'account_id' => 'integer',
    ];

    /**
     * Retorna a classe CSS para o ícone baseado no tipo.
     */
    public function getIconClass(): string
    {
        return match($this->type) {
            'warning' => 'fa-radiation text-warning',
            'error'   => 'fa-circle-exclamation text-danger',
            'success' => 'fa-check-circle text-success',
            default   => 'fa-bell text-info',
        };
    }
}
