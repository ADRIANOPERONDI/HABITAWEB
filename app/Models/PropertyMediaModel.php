<?php

namespace App\Models;

use CodeIgniter\Model;

class PropertyMediaModel extends Model
{
    protected $table            = 'property_media';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\PropertyMedia::class;
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'property_id', 'tipo', 'url', 'ordem', 'principal', 'created_at'
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [
        'principal' => 'boolean'  // PostgreSQL retorna 't'/'f' como string, forçar cast para boolean
    ];
    protected array $castHandlers = [];

    // Dates
    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = null;
    protected $deletedField  = 'deleted_at';

    // Validation
    protected $validationRules      = [];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    // Callbacks
    protected $allowCallbacks = true;
    protected $beforeInsert   = [];
    protected $afterInsert    = [];
    protected $beforeUpdate   = [];
    protected $afterUpdate    = [];
    protected $beforeFind     = [];
    protected $afterFind      = [];
    protected $beforeDelete   = [];
    protected $afterDelete    = [];

    /**
     * Conta mídias de uma propriedade
     */
    public function countByProperty(int $propertyId): int
    {
        return $this->where('property_id', $propertyId)->countAllResults();
    }

    /**
     * Define uma imagem como principal e desmarca as outras.
     */
    public function setMainMedia(int $propertyId, int $mediaId)
    {
        $this->db->transStart();
        
        // Reset all
        $this->where('property_id', $propertyId)
             ->set(['principal' => false])
             ->update();
             
        // Set new main
        $this->update($mediaId, ['principal' => true]);
        
        $this->db->transComplete();
        
        return $this->db->transStatus();
    }

    /**
     * Garante que apenas a imagem mais antiga seja a principal (Correção de Race Condition).
     */
    public function sanitizeMain(int $propertyId)
    {
        // 1. Desativar todas
        $this->db->query(
            "UPDATE property_media SET principal = false WHERE property_id = ?",
            [$propertyId]
        );
        
        // 2. Ativar apenas a mais antiga
        $this->db->query(
            "UPDATE property_media 
             SET principal = true
             WHERE id = (
                 SELECT id FROM property_media 
                 WHERE property_id = ? 
                 ORDER BY id ASC 
                 LIMIT 1
             )",
            [$propertyId]
        );
    }
}
