<?php

namespace App\Models;

use CodeIgniter\Model;

class PaymentGatewayConfigModel extends Model
{
    protected $table = 'payment_gateway_configs';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'object';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'gateway_id',
        'config_key',
        'config_value',
        'config_type',
        'is_sensitive',
        'display_order'
    ];

    // Dates
    protected $useTimestamps = false; // Gerenciado por default no DB (created_at)

    // Validation
    protected $validationRules = [
        'gateway_id' => 'required|integer',
        'config_key' => 'required|max_length[100]',
    ];

    protected $validationMessages = [];

    /**
     * Obter configurações de um gateway como array associativo
     */
    public function getGatewayConfig($gatewayId, $decrypted = true)
    {
        $configs = $this->where('gateway_id', $gatewayId)
                        ->orderBy('display_order', 'ASC')
                        ->findAll();
        
        $result = [];
        
        foreach ($configs as $config) {
            $value = $config->config_value;
            
            // Descriptografar se necessário e solicitado
            if ($decrypted && $config->is_sensitive && !empty($value)) {
                $value = $this->decryptValue($value, $config);
            }
            
            // Cast de tipo
            $value = $this->castConfigValue($value, $config->config_type);
            
            $result[$config->config_key] = $value;
        }
        
        return $result;
    }

    /**
     * Salvar configuração (com criptografia se sensível)
     */
    public function saveConfig($gatewayId, $key, $value, $isSensitive = false)
    {
        $existing = $this->where('gateway_id', $gatewayId)
                         ->where('config_key', $key)
                         ->first();
        
        // Criptografar se sensível
        if ($isSensitive && !empty($value)) {
            $value = $this->encryptValue($value);
        }
        
        $data = [
            'gateway_id' => $gatewayId,
            'config_key' => $key,
            'config_value' => $value,
            'is_sensitive' => $isSensitive
        ];
        
        if ($existing) {
            return $this->update($existing->id, $data);
        } else {
            return $this->insert($data);
        }
    }

    /**
     * Criptografar valor
     */
    protected function encryptValue($value)
    {
        $encrypter = \Config\Services::encrypter();
        return base64_encode($encrypter->encrypt($value));
    }

    /**
     * Descriptografar valor
     */
    protected function decryptValue($value, ?object $config = null)
    {
        $decoded = base64_decode((string) $value, true);

        if ($decoded === false) {
            return $this->recoverSensitiveValue($config, 'invalid_base64') ?? '';
        }

        try {
            $encrypter = \Config\Services::encrypter();
            return $encrypter->decrypt($decoded);
        } catch (\Exception $e) {
            $fallback = $this->recoverSensitiveValue($config, $e->getMessage());

            if ($fallback !== null) {
                return $fallback;
            }

            log_message('error', 'Erro ao descriptografar config ' . ($config->config_key ?? 'unknown') . ': ' . $e->getMessage());
            return '';
        }
    }

    protected function recoverSensitiveValue(?object $config, string $reason): ?string
    {
        if (!$config || empty($config->config_key)) {
            return null;
        }

        $envKey = match ($config->config_key) {
            'api_key'        => 'ASAAS_API_KEY',
            'webhook_secret' => 'ASAAS_WEBHOOK_SECRET',
            'webhook_token'  => 'ASAAS_WEBHOOK_TOKEN',
            default          => null,
        };

        if ($envKey === null) {
            return null;
        }

        $fallback = env($envKey, '');

        if ($fallback === '' && $config->config_key === 'webhook_secret') {
            $fallback = env('ASAAS_WEBHOOK_TOKEN', '');
        }

        if ($fallback === '') {
            return null;
        }

        log_message('warning', "Config sensível {$config->config_key} recuperada de {$envKey} após falha de descriptografia ({$reason}); recriptografando com a chave atual.");
        $this->saveConfig((int) $config->gateway_id, (string) $config->config_key, $fallback, true);

        return $fallback;
    }

    /**
     * Cast de valor conforme tipo
     */
    protected function castConfigValue($value, $type)
    {
        switch ($type) {
            case 'boolean':
                return (bool) $value;
            case 'integer':
                return (int) $value;
            case 'float':
                return (float) $value;
            default:
                return $value;
        }
    }
}
