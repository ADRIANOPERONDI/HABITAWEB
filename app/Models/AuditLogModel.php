<?php

namespace App\Models;

use CodeIgniter\Model;

class AuditLogModel extends Model
{
    protected $table         = 'audit_logs';
    protected $primaryKey    = 'id';
    protected $returnType    = 'object';
    protected $useTimestamps = false; // gravamos created_at manualmente no helper
    protected $allowedFields = [
        'actor_user_id', 'account_id', 'action', 'entity_type', 'entity_id',
        'ip_address', 'user_agent', 'metadata', 'created_at',
    ];
}
