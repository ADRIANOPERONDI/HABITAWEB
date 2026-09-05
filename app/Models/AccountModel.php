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
        'liveness_data',
        // Subconta (imobiliária -> corretor). Sem isto no allowedFields, o
        // insert de subconta feito por Api\V1\AccountController::create()
        // descartava o vínculo silenciosamente.
        'parent_account_id',
        // Isenção de cobrança por lead (Fase 3) — contas internas/superadmin.
        'cobranca_leads_isenta',
        // Perfil público da imobiliária (Fase 5).
        'slug', 'cep', 'estado', 'cidade', 'bairro', 'rua', 'numero', 'complemento',
        'latitude', 'longitude', 'descricao', 'capa', 'site', 'horario_atendimento',
        'instagram', 'facebook', 'linkedin', 'youtube', 'tiktok',
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    /**
     * O PostgreSQL devolve booleano como 'f'/'t', e a string 'f' é truthy em
     * PHP — sem este cast, $account->is_verified era SEMPRE true e o selo de
     * "parceiro verificado" aparecia para contas nunca verificadas
     * (app/Views/web/home.php exibe $partner->is_verified). Mesmo risco para
     * cobranca_leads_isenta: sem cast, toda conta pareceria isenta.
     */
    protected array $casts = [
        'is_verified'            => 'boolean',
        'cobranca_leads_isenta'  => 'boolean',
    ];
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
    protected $beforeInsert   = ['generateSlug'];
    protected $afterInsert    = ['invalidatePublicCaches'];
    protected $beforeUpdate   = [];
    protected $afterUpdate    = ['invalidatePublicCaches'];
    protected $beforeFind     = [];
    protected $afterFind      = [];
    protected $beforeDelete   = [];
    protected $afterDelete    = ['invalidatePublicCaches'];

    protected function invalidatePublicCaches(array $data): array
    {
        \App\Services\PublicPropertyVisibilityService::invalidateCaches();
        return $data;
    }

    /**
     * Slug é gerado uma vez, na criação, e nunca no update — mudar o slug
     * quebraria links já divulgados de `imobiliaria/(:segment)`. `mb_url_title`
     * translitera acentos antes de virar slug (nome de imobiliária é quase
     * sempre acentuado), diferente do `url_title` puro.
     */
    protected function generateSlug(array $data): array
    {
        $row = $data['data'] ?? [];
        if (! empty($row['slug']) || empty($row['nome'])) {
            return $data;
        }

        helper('text');
        $base = mb_url_title((string) $row['nome'], '-', true);
        if ($base === '') {
            $base = 'conta';
        }

        $slug = $base;
        $suffix = 1;
        while ($this->where('slug', $slug)->countAllResults() > 0) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        $data['data']['slug'] = $slug;

        return $data;
    }

    /**
     * Verifica se a conta tem todas as verificações KYC completas
     * Requer: id_front, id_back, selfie, verification_status aprovado e is_verified === true
     * @return bool
     */
    public function isFullyVerified(): bool
    {
        return !empty($this->id_front) 
               && !empty($this->id_back) 
               && !empty($this->selfie) 
               && $this->is_verified === true
               && in_array($this->verification_status, ['APPROVED', 'VERIFIED'], true);
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
            'APPROVED' => 'Verificado',
            'VERIFIED' => 'Verificado',
            'REJECTED' => 'Rejeitado',
            'EXPIRED' => 'Expirado',
        ];
        
        return $labels[$this->verification_status] ?? 'Desconhecido';
    }
}
