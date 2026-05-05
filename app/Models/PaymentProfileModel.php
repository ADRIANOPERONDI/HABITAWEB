<?php

namespace App\Models;

use CodeIgniter\Model;

class PaymentProfileModel extends Model
{
    protected $table = 'payment_profiles';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;
    protected $protectFields = true;
    protected $allowedFields = [
        'account_id',
        'gateway',
        'external_token',
        'last_digits',
        'brand',
        'status',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';
}
