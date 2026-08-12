<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Integração contratada por um tenant (uma linha por conta + conector).
 */
class AccountIntegrationModel extends Model
{
    protected $table            = 'account_integrations';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\AccountIntegration::class;
    protected $allowedFields    = [
        'account_id', 'provider_code', 'is_active', 'status',
        'last_test_at', 'last_test_message', 'last_sync_at', 'sync_cursor', 'settings',
    ];

    // is_active PRECISA do cast no model: Postgres devolve 'f', que é truthy
    // em PHP, e o comando de sync rodaria em integrações desligadas.
    protected array $casts = [
        'is_active'   => 'boolean',
        'sync_cursor' => '?json[array]',
        'settings'    => '?json[array]',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public const STATUS_PENDING   = 'PENDING';
    public const STATUS_CONNECTED = 'CONNECTED';
    public const STATUS_ERROR     = 'ERROR';
    public const STATUS_PAUSED    = 'PAUSED';

    public function findForAccount(int $accountId, string $providerCode): ?\App\Entities\AccountIntegration
    {
        return $this->where('account_id', $accountId)
            ->where('provider_code', $providerCode)
            ->first();
    }

    /** @return \App\Entities\AccountIntegration[] */
    public function listForAccount(int $accountId): array
    {
        return $this->where('account_id', $accountId)->findAll();
    }

    /**
     * Integrações elegíveis ao sync automático, mais antigas primeiro.
     *
     * ERROR fica de fora de propósito: se a credencial está inválida, insistir
     * a cada 30 min só empilha erro. O tenant reabilita testando a conexão.
     */
    public function dueForSync(?string $providerCode = null, ?int $accountId = null, int $limit = 50): array
    {
        $builder = $this->where('is_active', true)
            ->whereIn('status', [self::STATUS_CONNECTED, self::STATUS_PENDING]);

        if ($providerCode !== null) {
            $builder->where('provider_code', $providerCode);
        }

        if ($accountId !== null) {
            $builder->where('account_id', $accountId);
        }

        return $builder->orderBy('last_sync_at', 'ASC')->findAll($limit);
    }

    public function markTested(int $id, bool $ok, string $message): bool
    {
        return (bool) $this->update($id, [
            'status'            => $ok ? self::STATUS_CONNECTED : self::STATUS_ERROR,
            'last_test_at'      => date('Y-m-d H:i:s'),
            'last_test_message' => mb_substr($message, 0, 500),
        ]);
    }
}
