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
        'carencia_dias', 'ativo', 'descricao',
        'features', 'credito_leads_mensal', 'exposure_weight', 'turbo_bonus_anual'
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    /**
     * Casts no MODEL, não na entity.
     *
     * O `$casts` da entity não se aplica ao que o model lê do banco — é o alerta
     * registrado no CLAUDE.md. E ele importa aqui por dois motivos concretos:
     * o Postgres devolve boolean como 't'/'f', e a string 'f' é truthy no PHP
     * (um plano inativo passaria por ativo em qualquer `if ($plan->ativo)`); e
     * `features` chega como JSON string, que precisa virar array antes de
     * qualquer leitura de flag.
     */
    protected array $casts = [
        'features' => 'json-array',
        'ativo'    => 'boolean',
    ];
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
