<?php

namespace App\Models;

use CodeIgniter\Model;

class PropertyFavoriteModel extends Model
{
    protected $table            = 'property_favorites';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\PropertyFavorite::class;
    protected $allowedFields    = ['user_id', 'property_id'];

    protected $useTimestamps = true;
    protected $updatedField  = ''; // Não tem updated_at
    protected $createdField  = 'created_at';
}
