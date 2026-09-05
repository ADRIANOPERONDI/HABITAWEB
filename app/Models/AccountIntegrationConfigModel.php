<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Credenciais da integração, por tenant.
 *
 * A cifragem é REVERSÍVEL (Services::encrypter()), diferente de
 * ApiKeyModel::generateKey(), que usa bcrypt. A diferença importa: uma API key
 * de ENTRADA só precisa ser verificada (hash one-way basta), enquanto o token
 * de SAÍDA precisa ser reproduzido em toda chamada ao Simob.
 *
 * Não existe fallback de .env como em PaymentGatewayConfigModel: lá a
 * credencial é única e global (a chave Asaas da plataforma), aqui é uma por
 * tenant e não há onde guardá-las no ambiente. Se a decifragem falhar
 * (encryption.key rotacionada sem previousKeys), o valor é tratado como
 * ausente e o painel pede que o tenant recadastre — nunca se envia string
 * vazia como se fosse credencial válida.
 */
class AccountIntegrationConfigModel extends Model
{
    protected $table            = 'account_integration_configs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\AccountIntegrationConfig::class;
    protected $allowedFields    = [
        'account_integration_id', 'config_key', 'config_value', 'is_sensitive', 'last_four',
    ];

    protected array $casts = [
        'is_sensitive' => 'boolean',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Todas as credenciais da integração, já decifradas.
     *
     * Uma chave sensível ilegível é OMITIDA do array em vez de virar ''. Assim
     * o chamador cai na validação de "credencial faltando" em vez de fazer uma
     * chamada autenticada com token vazio.
     *
     * @return array<string, string>
     */
    public function getConfig(int $accountIntegrationId, bool $decrypted = true): array
    {
        $rows   = $this->where('account_integration_id', $accountIntegrationId)->findAll();
        $config = [];

        foreach ($rows as $row) {
            if (! $row->is_sensitive) {
                $config[$row->config_key] = (string) $row->config_value;
                continue;
            }

            if (! $decrypted) {
                $config[$row->config_key] = $this->maskedValue($row);
                continue;
            }

            $plain = $this->decryptValue((string) $row->config_value, $row);

            if ($plain !== null && $plain !== '') {
                $config[$row->config_key] = $plain;
            }
        }

        return $config;
    }

    /**
     * Versão para exibição: sensíveis viram ••••1234, nunca decifradas.
     *
     * @return array<string, string>
     */
    public function getMaskedConfig(int $accountIntegrationId): array
    {
        return $this->getConfig($accountIntegrationId, false);
    }

    /**
     * Upsert de uma credencial.
     *
     * Valor vazio numa chave sensível significa "o usuário não digitou nada,
     * mantenha o que já está lá" — o painel sempre renderiza o campo de senha
     * em branco, então sobrescrever com '' apagaria o token a cada save.
     */
    public function saveConfig(int $accountIntegrationId, string $key, ?string $value, bool $isSensitive = false): bool
    {
        $existing = $this->where('account_integration_id', $accountIntegrationId)
            ->where('config_key', $key)
            ->first();

        $value = $value === null ? '' : trim($value);

        if ($isSensitive && $value === '') {
            return $existing !== null;
        }

        $data = [
            'account_integration_id' => $accountIntegrationId,
            'config_key'             => $key,
            'config_value'           => $isSensitive ? $this->encryptValue($value) : $value,
            'is_sensitive'           => $isSensitive,
            'last_four'              => $isSensitive && $value !== '' ? mb_substr($value, -4) : null,
        ];

        if ($existing !== null) {
            return (bool) $this->update($existing->id, $data);
        }

        return (bool) $this->insert($data);
    }

    /** Remove todas as credenciais de uma integração (desconectar). */
    public function clearConfig(int $accountIntegrationId): bool
    {
        return (bool) $this->where('account_integration_id', $accountIntegrationId)->delete();
    }

    protected function encryptValue(string $value): string
    {
        return base64_encode(\Config\Services::encrypter()->encrypt($value));
    }

    /** @return string|null null = ilegível; o chamador trata como ausente. */
    protected function decryptValue(string $value, ?object $config = null): ?string
    {
        if ($value === '') {
            return null;
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            log_message('error', 'Credencial de integração com base64 inválido: ' . ($config->config_key ?? '?'));

            return null;
        }

        try {
            return \Config\Services::encrypter()->decrypt($decoded);
        } catch (\Throwable $e) {
            // Log sem o valor e sem a mensagem crua do encrypter, que pode
            // vazar fragmentos do payload.
            log_message(
                'error',
                'Falha ao decifrar credencial de integração ' . ($config->config_key ?? '?')
                . ' (integração ' . ($config->account_integration_id ?? '?') . '). '
                . 'Provável rotação de encryption.key — o tenant precisa recadastrar.'
            );

            return null;
        }
    }

    private function maskedValue(object $row): string
    {
        return $row->last_four ? '••••' . $row->last_four : '••••';
    }
}
