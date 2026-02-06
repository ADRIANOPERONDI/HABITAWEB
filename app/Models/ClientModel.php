<?php

namespace App\Models;

use CodeIgniter\Model;
use App\Entities\Client;

class ClientModel extends Model
{
    protected $table            = 'clients';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = Client::class;
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'account_id',
        'nome',
        'email',
        'telefone',
        'cpf_cnpj',
        'tipo_cliente',
        'notas'
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    // Validation
    protected $validationRules      = [
        'account_id' => 'required|is_natural_no_zero',
        'nome'       => 'required|min_length[3]',
        'email'      => 'permit_empty|valid_email',
    ];
    protected $validationMessages   = [];
    protected $skipValidation       = false;
    protected $cleanValidationRules = true;
}
