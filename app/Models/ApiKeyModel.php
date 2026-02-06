<?php

namespace App\Models;

use CodeIgniter\Model;

class ApiKeyModel extends Model
{
    protected $table            = 'api_keys';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\ApiKey::class;
    protected $useSoftDeletes   = true;
    protected $allowedFields    = [
        'account_id',
        'name',
        'key_hash',
        'prefix',
        'last_four',
        'status',
        'rate_limit_per_hour',
        'last_used_at',
        'last_used_ip',
        'expires_at',
        'created_by_user_id'
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    protected $validationRules = [
        'account_id' => 'required|integer',
        'name' => 'required|min_length[3]|max_length[100]',
        'key_hash' => 'required',
        'prefix' => 'required',
        'last_four' => 'required',
        'status' => 'in_list[active,inactive,revoked]',
        'rate_limit_per_hour' => 'permit_empty|integer',
    ];

    protected $validationMessages = [
        'account_id' => [
            'required' => 'A conta é obrigatória.',
        ],
        'name' => [
            'required' => 'O nome da chave é obrigatório.',
            'min_length' => 'O nome deve ter pelo menos 3 caracteres.',
        ],
    ];

    /**
     * Gera uma nova API key para uma conta
     */
    public function generateKey(int $accountId, string $name, int $createdByUserId, ?int $rateLimitPerHour = 1000, ?\DateTime $expiresAt = null): array
    {
        // Gera a chave em plain text (será mostrada apenas uma vez)
        $plainKey = $this->generateRandomKey();
        
        // Separa prefixo e sufixo para exibição
        $prefix = substr($plainKey, 0, 8);
        $lastFour = substr($plainKey, -4);
        
        // Hash bcrypt para armazenamento seguro
        $keyHash = password_hash($plainKey, PASSWORD_BCRYPT);

        $data = [
            'account_id' => $accountId,
            'name' => $name,
            'key_hash' => $keyHash,
            'prefix' => $prefix,
            'last_four' => $lastFour,
            'status' => 'active',
            'rate_limit_per_hour' => $rateLimitPerHour,
            'expires_at' => $expiresAt ? $expiresAt->format('Y-m-d H:i:s') : null,
            'created_by_user_id' => $createdByUserId,
        ];

        if ($this->insert($data)) {
            $keyId = $this->getInsertID();
            return [
                'success' => true,
                'key_id' => $keyId,
                'plain_key' => $plainKey, // ATENÇÃO: mostrar apenas UMA VEZ
                'message' => 'API key gerada com sucesso. Copie agora, não será exibida novamente.',
            ];
        }

        return [
            'success' => false,
            'message' => 'Erro ao gerar API key.',
        ];
    }

    /**
     * Encontra chave por plain text (para autenticação)
     */
    public function findByPlainKey(string $plainKey): ?\App\Entities\ApiKey
    {
        $prefix = substr($plainKey, 0, 8);
        $keys = $this->where('prefix', $prefix)
                     ->where('status', 'active')
                     ->findAll();

        foreach ($keys as $key) {
            if ($key->verifyKey($plainKey)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Revoga uma chave
     */
    public function revokeKey(int $keyId): bool
    {
        return $this->update($keyId, ['status' => 'revoked']);
    }

    /**
     * Gera uma chave aleatória segura
     */
    private function generateRandomKey(): string
    {
        // Formato: pk_live_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX (40 chars)
        $env = ENVIRONMENT === 'production' ? 'live' : 'test';
        $random = bin2hex(random_bytes(16)); // 32 caracteres hex
        return "pk_{$env}_{$random}";
    }
}
