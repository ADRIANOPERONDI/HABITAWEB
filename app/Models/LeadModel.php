<?php

namespace App\Models;

use CodeIgniter\Model;

class LeadModel extends Model
{
    protected $table            = 'leads';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\Lead::class;
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'property_id', 'account_id_anunciante', 'user_id_responsavel', 'nome_visitante', 
        'telefone_visitante', 'email_visitante', 'mensagem', 'origem', 'tipo_lead', 'status',
        'closed_at', 'closing_value', 'closing_notes'
    ];

    // Constantes de Status para CRM
    const STATUS_NOVO           = 'NOVO';
    const STATUS_ATENDIMENTO    = 'EM_ATENDIMENTO';
    const STATUS_CONCLUIDO      = 'CONCLUIDO';
    const STATUS_PERDIDO        = 'PERDIDO';

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [];
    protected array $castHandlers = [];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = null;

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
     * Aplica filtros de leads baseados em atributos do imóvel (bairro, condominio, corretor).
     */
    protected function applyPropertyFilters($query, array $filters = [], ?int $brokerId = null)
    {
        $db = \Config\Database::connect();
        
        $subQuery = $db->table('properties')
            ->select('id')
            ->where('deleted_at IS NULL');
            
        if ($brokerId) {
            $subQuery->where('user_id_responsavel', $brokerId);
        }
        if (!empty($filters['bairro'])) {
            $subQuery->where('bairro', $filters['bairro']);
        }
        if (!empty($filters['condominio'])) {
            $subQuery->where('condominio', $filters['condominio']);
        }
        
        // Convert to SQL for the WHERE IN
        $sql = $subQuery->getCompiledSelect();
        
        $query->where("property_id IN ($sql)", null, false);
        
        return $query;
    }

    /**
     * Conta leads recebidos hoje com filtros.
     */
    public function countTodayWithFilters(int $accountId, array $filters = [], ?int $brokerId = null): int
    {
        $query = $this->where('account_id_anunciante', $accountId)
                      ->where('created_at >=', date('Y-m-d 00:00:00'));
        
        $this->applyPropertyFilters($query, $filters, $brokerId);
        
        return $query->countAllResults();
    }

    /**
     * Conta total de leads com filtros.
     */
    public function countTotalWithFilters(int $accountId, array $filters = [], ?int $brokerId = null): int
    {
        $query = $this->where('account_id_anunciante', $accountId);
        $this->applyPropertyFilters($query, $filters, $brokerId);
        
        return $query->countAllResults();
    }

    /**
     * Busca leads dos últimos 7 dias para o gráfico.
     */
    public function getLeadsLast7Days(int $accountId, array $filters = [], ?int $brokerId = null): array
    {
        $query = $this->select('created_at text_created_at') // Hack to avoid Time object issues in simple logic if needed
            ->select('created_at')
            ->where('account_id_anunciante', $accountId)
            ->where('created_at >=', date('Y-m-d', strtotime('-7 days')));
            
        $this->applyPropertyFilters($query, $filters, $brokerId);
        
        return $query->findAll();
    }
}
