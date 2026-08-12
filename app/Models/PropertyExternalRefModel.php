<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Vínculo imóvel do Habitaweb <-> registro na plataforma externa.
 *
 * Ver a migration CreatePropertyExternalRefsTable para o porquê de não reusar
 * properties.external_id.
 */
class PropertyExternalRefModel extends Model
{
    protected $table            = 'property_external_refs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\PropertyExternalRef::class;
    protected $allowedFields    = [
        'property_id', 'account_id', 'provider_code', 'external_id', 'external_code',
        'external_updated_at', 'payload_hash', 'last_synced_at', 'last_sync_run_id',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public function findRef(int $accountId, string $providerCode, string $externalId): ?\App\Entities\PropertyExternalRef
    {
        return $this->where('account_id', $accountId)
            ->where('provider_code', $providerCode)
            ->where('external_id', $externalId)
            ->first();
    }

    public function findByProperty(int $propertyId): ?\App\Entities\PropertyExternalRef
    {
        return $this->where('property_id', $propertyId)->first();
    }

    /**
     * O imóvel é espelho de alguma plataforma externa?
     *
     * Usado para travar a edição no admin — o Simob é a fonte da verdade e o
     * próximo sync sobrescreveria qualquer alteração feita aqui.
     */
    public function isManaged(int $propertyId): bool
    {
        return $this->where('property_id', $propertyId)->countAllResults() > 0;
    }

    public function upsertRef(array $data): bool
    {
        $existing = $this->findRef(
            (int) $data['account_id'],
            (string) $data['provider_code'],
            (string) $data['external_id']
        );

        if ($existing !== null) {
            return (bool) $this->update($existing->id, $data);
        }

        return (bool) $this->insert($data);
    }

    /**
     * IDs de imóveis que a rodada corrente NÃO visitou.
     *
     * São os que sumiram do catálogo da origem — viram PAUSED, nunca deletados:
     * podem ter leads e histórico atrelados.
     *
     * A comparação é pelo id da rodada, e não por last_synced_at: a coluna de
     * data tem precisão de segundo, então duas rodadas dentro do mesmo segundo
     * não seriam distinguidas e nenhum sumiço seria detectado.
     *
     * @return int[]
     */
    public function staleProperties(int $accountId, string $providerCode, int $runId): array
    {
        $rows = $this->select('property_id')
            ->where('account_id', $accountId)
            ->where('provider_code', $providerCode)
            ->groupStart()
                ->where('last_sync_run_id !=', $runId)
                ->orWhere('last_sync_run_id', null)
            ->groupEnd()
            ->findAll();

        return array_map(static fn ($row) => (int) $row->property_id, $rows);
    }
}
