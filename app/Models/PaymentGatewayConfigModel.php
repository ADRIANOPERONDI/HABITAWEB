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
                $value = $this->decryptValue($value);
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
    protected function decryptValue($value)
    {
        try {
            $encrypter = \Config\Services::encrypter();
            return $encrypter->decrypt(base64_decode($value));
        } catch (\Exception $e) {
            log_message('error', 'Erro ao descriptografar config: ' . $e->getMessage());
            return $value; // Retorna valor original se falhar
        }
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
