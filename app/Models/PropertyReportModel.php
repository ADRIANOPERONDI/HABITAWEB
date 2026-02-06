<?php

namespace App\Models;

use CodeIgniter\Model;

class PropertyReportModel extends Model
{
    protected $table            = 'property_reports';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array'; // return array for now or entity if created
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'property_id', 'user_id', 'ip_address', 'reason', 
        'type', 'status', 'resolution_notes'
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';
}
