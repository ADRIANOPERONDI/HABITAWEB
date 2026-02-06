<?php

namespace App\Models;

use CodeIgniter\Model;

class PaymentGatewayModel extends Model
{
    protected $table = 'payment_gateways';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'object';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'code',
        'name',
        'class_name',
        'is_active',
        'is_primary',
        'logo_url',
        'description',
        'supported_methods'
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    // Validation
    protected $validationRules = [
        'code' => 'required|max_length[50]|is_unique[payment_gateways.code,id,{id}]',
        'name' => 'required|max_length[100]',
        'class_name' => 'required|max_length[255]',
    ];

    protected $validationMessages = [
        'code' => ['required' => 'O código do gateway é obrigatório', 'is_unique' => 'Já existe um gateway com este código']
    ];

    // Callbacks
    protected $beforeInsert = [];
    protected $afterInsert = [];
    protected $beforeUpdate = [];
    protected $afterUpdate = [];
    protected $beforeFind = [];
    protected $afterFind = ['castJson'];
    protected $beforeDelete = [];
    protected $afterDelete = [];

    /**
     * Cast campo JSONB para array
     */
    protected function castJson(array $data)
    {
        if (empty($data['data'])) {
            return $data;
        }

        // singleton ou is_array(data) ajuda a decidir como iterar
        $items = $data['data'];
        $singleton = $data['singleton'] ?? (!isset($items[0]) && !empty($items));

        if ($singleton) {
            $this->castSingleJson($data['data']);
        } else {
            foreach ($data['data'] as &$item) {
                $this->castSingleJson($item);
            }
        }
        
        return $data;
    }

    /**
     * Auxiliar para converter campo JSON e humanizar métodos
     */
    protected function castSingleJson(&$item)
    {
        if (is_object($item)) {
            $item->is_active = $this->toBool($item->is_active);
            $item->is_primary = $this->toBool($item->is_primary);
            $methods = $item->supported_methods ?? null;
        } elseif (is_array($item)) {
            $item['is_active'] = $this->toBool($item['is_active'] ?? false);
            $item['is_primary'] = $this->toBool($item['is_primary'] ?? false);
            $methods = $item['supported_methods'] ?? null;
        }

        if (is_string($methods)) {
            $decoded = json_decode($methods, true);
            
            if (is_object($item)) {
                $item->supported_methods = $decoded;
                $item->human_methods = $this->humanizeMethods($decoded);
            } else {
                $item['supported_methods'] = $decoded;
                $item['human_methods'] = $this->humanizeMethods($decoded);
            }
        }
    }

    /**
     * Conversor robusto para booleanos do Postgres
     */
    protected function toBool($val)
    {
        if (is_bool($val)) return $val;
        if (is_null($val)) return false;
        
        $val = strtolower((string)$val);
        return in_array($val, ['1', 'true', 't', 'yes', 'y', 'on']);
    }

    /**
     * Traduz e humaniza os métodos de pagamento
     */
    protected function humanizeMethods(?array $methods)
    {
        if (empty($methods)) return [];

        $map = [
            'PIX'         => 'Pix',
            'BOLETO'      => 'Boleto',
            'CREDIT_CARD' => 'Cartão de Crédito',
            'DEBIT_CARD'  => 'Cartão de Débito',
            'TRANSFER'    => 'Transferência'
        ];

        return array_map(fn($m) => $map[strtoupper($m)] ?? ucfirst(strtolower($m)), $methods);
    }

    /**
     * Obter gateway primário ativo
     */
    public function getPrimaryGateway()
    {
        return $this->where('is_active', true)
                    ->where('is_primary', true)
                    ->first();
    }

    /**
     * Obter todos os gateways ativos
     */
    public function getActiveGateways()
    {
        return $this->where('is_active', true)->findAll();
    }

    /**
     * Definir gateway como primário (desativa outros)
     */
    public function setPrimary($id)
    {
        $db = \Config\Database::connect();
        $db->transStart();
        
        // Reset manual via SQL para não depender do cast do Model aqui
        $db->query("UPDATE payment_gateways SET is_primary = false");
        
        // Define o escolhido como primário e ativo
        $db->table($this->table)
           ->where('id', $id)
           ->update(['is_primary' => true, 'is_active' => true]);
        
        $db->transComplete();
        
        return $db->transStatus();
    }
}
