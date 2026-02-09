<?php

namespace App\Models;

use CodeIgniter\Model;

class PropertyModel extends Model
{
    protected $table            = 'properties';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\Property::class;
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'account_id', 'client_id', 'user_id_responsavel', 'tipo_negocio', 'tipo_imovel', 'titulo', 'descricao',
        'preco', 'valor_condominio', 'iptu', 'area_total', 'area_construida', 'quartos', 'banheiros',
        'vagas', 'cep', 'estado', 'cidade', 'bairro', 'rua', 'numero', 'complemento', 'latitude',
        'longitude', 'status', 'visitas_count', 'leads_count', 'score_qualidade', 'publicado_em', 'atualizado_em',
        'last_validated_at', 'quality_warnings', 'moderation_status', 'auto_paused', 'auto_paused_reason',
        'duplicate_signature',
        'highlight_level', 'highlight_expires_at',
        'closed_at', 'closing_lead_id', 'closing_reason',
        'suites', 'is_destaque', 'is_novo', 'meta_title', 'meta_description',
        'is_exclusivo', 'aceita_pets', 'mobiliado', 'semimobiliado',
        'is_desocupado', 'is_locado', 'renda_mensal_estimada', 'condominio',
        'indicado_investidor', 'indicado_primeira_moradia', 'indicado_temporada'
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [
        'is_destaque'      => 'boolean',
        'is_novo'          => 'boolean',
        'is_exclusivo'     => 'boolean',
        'aceita_pets'      => 'boolean',
        'mobiliado'        => 'boolean',
        'semimobiliado'    => 'boolean',
        'is_desocupado'    => 'boolean',
        'is_locado'        => 'boolean',
        'indicado_investidor'      => 'boolean',
        'indicado_primeira_moradia' => 'boolean',
        'indicado_temporada'       => 'boolean',
        'auto_paused'              => 'boolean',
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
    protected $beforeInsert   = [];
    protected $afterInsert    = [];
    protected $beforeUpdate   = [];
    protected $afterUpdate    = [];
    protected $beforeFind     = [];
    protected $afterFind      = [];
    protected $beforeDelete   = [];
    protected $afterDelete    = [];

    /**
     * Busca bairros únicos de uma conta.
     */
    public function getDistinctBairros(int $accountId): array
    {
        return $this->distinct()
            ->select('bairro')
            ->where('account_id', $accountId)
            ->where('bairro !=', '')
            ->where('bairro IS NOT NULL')
            ->orderBy('bairro', 'ASC')
            ->findColumn('bairro') ?? [];
    }

    /**
     * Busca condomínios únicos de uma conta.
     */
    public function getDistinctCondominios(int $accountId): array
    {
        return $this->distinct()
            ->select('condominio')
            ->where('account_id', $accountId)
            ->where('condominio !=', '')
            ->where('condominio IS NOT NULL')
            ->orderBy('condominio', 'ASC')
            ->findColumn('condominio') ?? [];
    }

    /**
     * Aplica filtros comuns de dashboard (bairro, condominio, corretor).
     */
    protected function applyDashboardFilters($query, array $filters = [], ?int $brokerId = null)
    {
        if (!empty($filters['bairro'])) {
            $query->where('bairro', $filters['bairro']);
        }
        if (!empty($filters['condominio'])) {
            $query->where('condominio', $filters['condominio']);
        }
        if ($brokerId) {
            $query->where('user_id_responsavel', $brokerId);
        }
        return $query;
    }

    /**
     * Conta imóveis ativos com filtros.
     */
    public function countActiveWithFilters(int $accountId, array $filters = [], ?int $brokerId = null): int
    {
        $query = $this->where('account_id', $accountId)
                      ->where('status', 'ACTIVE');
        
        $this->applyDashboardFilters($query, $filters, $brokerId);
        
        return $query->countAllResults();
    }

    /**
     * Soma total de visitas com filtros.
     */
    public function sumVisitsWithFilters(int $accountId, array $filters = [], ?int $brokerId = null): int
    {
        $query = $this->where('account_id', $accountId);
        $this->applyDashboardFilters($query, $filters, $brokerId);
        
        return (int) $query->selectSum('visitas_count')->first()->visitas_count ?? 0;
    }

    /**
     * Busca imóveis recentes com capa e filtros.
     */
    public function getRecentWithFilters(int $accountId, int $limit = 5, array $filters = [], ?int $brokerId = null): array
    {
        $query = $this->select('properties.*')
            ->select('(SELECT url FROM property_media WHERE property_media.property_id = properties.id AND property_media.deleted_at IS NULL ORDER BY principal DESC, ordem ASC LIMIT 1) as cover_image')
            ->where('account_id', $accountId);
            
        $this->applyDashboardFilters($query, $filters, $brokerId);
        
        return $query->orderBy('created_at', 'DESC')->findAll($limit);
    }

    /**
     * Busca oportunidades (muitas visitas, zero leads).
     */
    public function getOpportunities(int $accountId, array $filters = [], ?int $brokerId = null, int $limit = 3): array
    {
        $query = $this->select('properties.*, 0 as leads_count')
            ->select('(SELECT url FROM property_media WHERE property_media.property_id = properties.id AND property_media.deleted_at IS NULL ORDER BY principal DESC, ordem ASC LIMIT 1) as cover_image')
            ->where('properties.account_id', $accountId)
            ->where('properties.status', 'ACTIVE')
            ->where('properties.visitas_count >', 20);
            
        $this->applyDashboardFilters($query, $filters, $brokerId);
        
        // Custom where for zero leads
        $query->where('(SELECT COUNT(*) FROM leads WHERE leads.property_id = properties.id) = 0', null, false);
        
        return $query->findAll($limit);
    }

    /**
     * Preço médio do usuário com filtros.
     */
    public function getAvgPrice(int $accountId, array $filters = [], ?int $brokerId = null): float
    {
        $query = $this->selectAvg('preco')
            ->where('account_id', $accountId);
            
        $this->applyDashboardFilters($query, $filters, $brokerId);
        
        return (float) ($query->first()->preco ?? 0);
    }

    /**
     * Preço médio de mercado com filtros.
     */
    public function getMarketAvgPrice(array $filters = []): float
    {
        $query = $this->selectAvg('preco')
            ->where('status', 'ACTIVE');
            
        if (!empty($filters['bairro'])) {
            $query->where('bairro', $filters['bairro']);
        }
        if (!empty($filters['condominio'])) {
            $query->where('condominio', $filters['condominio']);
        }
        
        return (float) ($query->first()->preco ?? 0);
    }

    /**
     * Calcula média de preço por bairro e tipo (últimos 6 meses)
     */
    public function getAveragePriceForNeighborhood(string $bairro, string $type): array
    {
        $sixMonthsAgo = date('Y-m-d H:i:s', strtotime('-6 months'));
        
        $result = $this->select('AVG(preco) as avg_price, COUNT(*) as count')
            ->where('bairro', $bairro)
            ->where('tipo_imovel', $type)
            ->where('status', 'ACTIVE')
            ->where('preco >', 0)
            ->where('created_at >=', $sixMonthsAgo)
            ->first();
            
        return [
            'avg_price' => $result->avg_price ?? 0,
            'count' => $result->count ?? 0
        ];
    }
}
