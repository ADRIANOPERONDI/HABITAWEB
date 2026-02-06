<?php

namespace App\Models;

use CodeIgniter\Model;

class PropertyAlertModel extends Model
{
    protected $table            = 'property_alerts';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'email', 'nome', 'whatsapp', 'filtros', 'frequencia', 'status', 'last_sent_at'
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [
        'filtros' => 'json'
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    // Validation
    protected $validationRules      = [
        'email'      => 'required|valid_email',
        'filtros'    => 'required',
        'frequencia' => 'required|in_list[IMEDIATO,DIARIO,SEMANAL]',
        'status'     => 'required|in_list[ATIVO,PAUSADO,CANCELADO]'
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;
}
