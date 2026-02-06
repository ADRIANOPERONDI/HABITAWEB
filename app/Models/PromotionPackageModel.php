<?php

namespace App\Models;

use CodeIgniter\Model;

class PromotionPackageModel extends Model
{
    protected $table            = 'promotion_packages';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\PromotionPackage::class;
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'chave', 'nome', 'tipo_promocao', 'duracao_dias', 'preco'
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
