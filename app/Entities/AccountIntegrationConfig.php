<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class AccountIntegrationConfig extends Entity
{
    protected $casts = [
        'id'                     => 'integer',
        'account_integration_id' => 'integer',
        'is_sensitive'           => 'boolean',
    ];

    /**
     * Nunca expõe o valor. Use AccountIntegrationConfigModel::getConfig()
     * quando precisar do valor real.
     */
    public function display(): string
    {
        if (! $this->is_sensitive) {
            return (string) $this->config_value;
        }

        return $this->last_four ? '••••' . $this->last_four : '••••';
    }
}
