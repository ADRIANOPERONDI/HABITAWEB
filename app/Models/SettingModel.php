<?php

namespace App\Models;

use CodeIgniter\Model;

class SettingModel extends Model
{
    protected $table            = 'system_settings';
    protected $primaryKey       = 'key';
    protected $useAutoIncrement = false;
    protected $returnType       = 'object';
    protected $allowedFields    = ['key', 'value', 'group', 'type', 'label', 'description'];
    protected $useTimestamps    = true;
}
