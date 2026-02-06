<?php

namespace App\Models;

use CodeIgniter\Model;

class IntegrationWebhookModel extends Model
{
    protected $table            = 'integration_webhooks';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\IntegrationWebhook::class;
    protected $allowedFields    = ['account_id', 'name', 'event', 'target_url', 'secret', 'is_active'];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
