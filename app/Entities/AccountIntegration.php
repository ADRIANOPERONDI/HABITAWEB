<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class AccountIntegration extends Entity
{
    protected $casts = [
        'id'         => 'integer',
        'account_id' => 'integer',
        'is_active'  => 'boolean',
    ];

    protected $dates = ['last_test_at', 'last_sync_at', 'created_at', 'updated_at'];

    /** Preferências de sincronização, com os padrões aplicados. */
    public function settings(): array
    {
        $settings = $this->attributes['settings'] ?? null;

        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        return array_merge(self::defaultSettings(), is_array($settings) ? $settings : []);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings()[$key] ?? $default;
    }

    public static function defaultSettings(): array
    {
        return [
            // Finalidades do Simob: 1 = locação, 2 = venda.
            'finalidades'     => [1, 2],
            // Imóvel novo entra como rascunho por padrão: o tenant revisa o
            // mapeamento antes de o catálogo inteiro cair no portal público.
            'initial_status'  => 'DRAFT',
            'import_images'   => true,
            'max_images'      => 20,
        ];
    }

    public function cursor(): array
    {
        $cursor = $this->attributes['sync_cursor'] ?? null;

        if (is_string($cursor)) {
            $cursor = json_decode($cursor, true);
        }

        return is_array($cursor) ? $cursor : [];
    }

    public function isConnected(): bool
    {
        return $this->attributes['status'] === \App\Models\AccountIntegrationModel::STATUS_CONNECTED;
    }
}
