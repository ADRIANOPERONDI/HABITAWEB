<?php

namespace App\Models;

use CodeIgniter\Model;

class PlanModel extends Model
{
    protected $table            = 'plans';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\Plan::class;
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'chave', 'nome', 'limite_imoveis_ativos', 'limite_turbo_mensal',
        'limite_api_requests_dia', 'preco_mensal', 'preco_trimestral',
        'preco_semestral', 'preco_anual', 'limite_fotos_por_imovel',
        'destaques_mensais', 'carencia_dias', 'ativo', 'descricao'
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
    protected $validationRules      = [
        'id' => 'permit_empty|is_natural_no_zero',
        'nome' => 'required|is_unique[plans.nome,id,{id}]',
        'chave' => 'required|is_unique[plans.chave,id,{id}]'
    ];
    protected $validationMessages   = [
        'nome' => [
            'is_unique' => 'Já existe um plano com este nome.'
        ],
        'chave' => [
            'is_unique' => 'Já existe um plano com esta chave/slug gerada. Tente um nome diferente.'
        ]
    ];

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
}
