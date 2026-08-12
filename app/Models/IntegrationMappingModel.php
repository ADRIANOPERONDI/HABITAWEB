<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * De/para entre IDs da plataforma externa e campos do Habitaweb, por tenant.
 */
class IntegrationMappingModel extends Model
{
    protected $table            = 'integration_mappings';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\IntegrationMapping::class;
    protected $allowedFields    = [
        'account_integration_id', 'kind', 'external_id', 'external_label',
        'external_type', 'target_field', 'target_value', 'is_confirmed',
    ];

    protected array $casts = [
        'is_confirmed' => 'boolean',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public const KIND_CATEGORY       = 'category';
    public const KIND_CHARACTERISTIC = 'characteristic';

    /**
     * Mapeamentos indexados por external_id, prontos para lookup O(1) no mapper.
     *
     * @return array<string, \App\Entities\IntegrationMapping>
     */
    public function indexedBy(int $accountIntegrationId, string $kind): array
    {
        $rows = $this->where('account_integration_id', $accountIntegrationId)
            ->where('kind', $kind)
            ->findAll();

        $indexed = [];

        foreach ($rows as $row) {
            $indexed[(string) $row->external_id] = $row;
        }

        return $indexed;
    }

    /** @return \App\Entities\IntegrationMapping[] */
    public function listByKind(int $accountIntegrationId, string $kind): array
    {
        return $this->where('account_integration_id', $accountIntegrationId)
            ->where('kind', $kind)
            ->orderBy('external_label', 'ASC')
            ->findAll();
    }

    /**
     * Insere a sugestão automática apenas se o par ainda não existir.
     *
     * Nunca sobrescreve: se o tenant já revisou aquele de/para, uma nova rodada
     * de descoberta não pode desfazer a escolha dele.
     */
    public function seedSuggestion(int $accountIntegrationId, string $kind, array $data): bool
    {
        $exists = $this->where('account_integration_id', $accountIntegrationId)
            ->where('kind', $kind)
            ->where('external_id', (string) $data['external_id'])
            ->first();

        if ($exists !== null) {
            // Atualiza só o rótulo, que pode ter sido renomeado na origem.
            if (isset($data['external_label']) && $exists->external_label !== $data['external_label']) {
                $this->update($exists->id, ['external_label' => $data['external_label']]);
            }

            return false;
        }

        return (bool) $this->insert(array_merge($data, [
            'account_integration_id' => $accountIntegrationId,
            'kind'                   => $kind,
            'is_confirmed'           => false,
        ]));
    }

    public function countUnconfirmed(int $accountIntegrationId): int
    {
        return $this->where('account_integration_id', $accountIntegrationId)
            ->where('is_confirmed', false)
            ->countAllResults();
    }
}
