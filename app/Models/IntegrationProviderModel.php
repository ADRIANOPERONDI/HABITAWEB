<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Catálogo global de conectores. Ver a migration CreateIntegrationProvidersTable.
 */
class IntegrationProviderModel extends Model
{
    protected $table            = 'integration_providers';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\IntegrationProvider::class;
    protected $allowedFields    = [
        'code', 'name', 'class_name', 'is_active', 'config_schema', 'capabilities', 'docs_url',
    ];

    // Postgres devolve booleano como 't'/'f' e a string 'f' é truthy em PHP:
    // sem o cast no MODEL (o da entity não vale para leitura de model),
    // todo conector apareceria como ativo.
    protected array $casts = [
        'is_active'     => 'boolean',
        'config_schema' => '?json[array]',
        'capabilities'  => '?json[array]',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function findByCode(string $code): ?\App\Entities\IntegrationProvider
    {
        return $this->where('code', $code)->first();
    }

    /** @return \App\Entities\IntegrationProvider[] */
    public function findActive(): array
    {
        return $this->where('is_active', true)->orderBy('name', 'ASC')->findAll();
    }
}
