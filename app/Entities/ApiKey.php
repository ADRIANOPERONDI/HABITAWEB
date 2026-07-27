<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;

class ApiKey extends Entity
{
    /**
     * A tabela guarda 'created_by_user_id', mas ApiAuth (e todo o código que
     * consome auth_user_id) fala em 'user_id'. Sem este mapa, $apiKey->user_id
     * devolvia null silenciosamente — o que fazia BaseController::isSuperAdmin()
     * retornar false até para chaves de superadmin, e gravava user_id nulo nas
     * denúncias de imóvel.
     */
    protected $datamap = [
        'user_id' => 'created_by_user_id',
    ];
    protected $dates   = ['created_at', 'updated_at', 'deleted_at', 'last_used_at', 'expires_at'];
    protected $casts   = [
        'id' => 'integer',
        'account_id' => 'integer',
        'rate_limit_per_hour' => 'integer',
        'created_by_user_id' => 'integer',
    ];

    /**
     * Verifica se a chave está ativa
     */
    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        // Verifica expiração
        if ($this->expires_at && strtotime($this->expires_at) < time()) {
            return false;
        }

        return true;
    }

    /**
     * Retorna o identificador visual da chave (prefixo + últimos 4)
     */
    public function getVisibleKey(): string
    {
        return $this->prefix . '...' . $this->last_four;
    }

    /**
     * Verifica se a chave fornecida bate com o hash
     */
    public function verifyKey(string $plainKey): bool
    {
        return password_verify($plainKey, $this->key_hash);
    }

    /**
     * Atualiza o tracking de uso
     */
    public function updateUsage(string $ipAddress): void
    {
        $this->last_used_at = date('Y-m-d H:i:s');
        $this->last_used_ip = $ipAddress;
    }
}
