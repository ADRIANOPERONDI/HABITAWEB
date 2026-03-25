<?php

namespace App\Models;

use CodeIgniter\Model;

class AccountModel extends Model
{
    protected $table            = 'accounts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\Account::class;
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'tipo_conta', 'nome', 'documento', 'email', 'telefone', 'whatsapp', 'creci', 'status', 'logo',
        'whatsapp_hub_config', 'whatsapp_messages_config',
        'is_verified', 'verification_status', 'id_front', 'id_back', 'selfie', 'verification_notes',
        'liveness_data'
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [];
    protected array $castHandlers = [];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
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
     * Verifica se a conta tem todas as verificações KYC completas
     * Requer: id_front, id_back, selfie, verification_status === 'VERIFIED', is_verified === true
     * @return bool
     */
    public function isFullyVerified(): bool
    {
        return !empty($this->id_front) 
               && !empty($this->id_back) 
               && !empty($this->selfie) 
               && $this->is_verified === true 
               && $this->verification_status === 'VERIFIED';
    }

    /**
     * Get o status de verificação de forma legível
     * @return string
     */
    public function getVerificationStatusLabel(): string
    {
        $labels = [
            'NONE' => 'Não iniciado',
            'PENDING' => 'Pendente de revisão',
            'VERIFIED' => 'Verificado',
            'REJECTED' => 'Rejeitado',
            'EXPIRED' => 'Expirado',
        ];
        
        return $labels[$this->verification_status] ?? 'Desconhecido';
    }
}
