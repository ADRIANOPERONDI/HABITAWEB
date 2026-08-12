<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

/**
 * Conector disponível no catálogo global.
 *
 * config_schema e capabilities já chegam como array — o cast é feito no
 * IntegrationProviderModel (o cast da entity não vale para leitura via model).
 */
class IntegrationProvider extends Entity
{
    protected $casts = [
        'id'        => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Campos de credencial que o painel deve renderizar.
     *
     * @return list<array{key:string,label:string,type:string,is_sensitive:bool,required:bool,placeholder?:string,help?:string}>
     */
    public function getSchemaFields(): array
    {
        $schema = $this->attributes['config_schema'] ?? null;

        if (is_string($schema)) {
            $schema = json_decode($schema, true);
        }

        return is_array($schema) ? $schema : [];
    }

    /** @return string[] */
    public function getCapabilities(): array
    {
        $caps = $this->attributes['capabilities'] ?? null;

        if (is_string($caps)) {
            $caps = json_decode($caps, true);
        }

        return is_array($caps) ? $caps : [];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->getCapabilities(), true);
    }

    /** Chaves do schema marcadas como sensíveis — precisam ser cifradas. */
    public function sensitiveKeys(): array
    {
        $keys = [];

        foreach ($this->getSchemaFields() as $field) {
            if (! empty($field['is_sensitive'])) {
                $keys[] = $field['key'];
            }
        }

        return $keys;
    }
}
