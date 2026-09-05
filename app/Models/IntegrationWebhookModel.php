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

    // is_active PRECISA do cast no model: Postgres devolve 'f', que é truthy em
    // PHP. Sem isto, GET/PATCH /api/v1/webhooks/{id} devolvia is_active=true na
    // resposta mesmo depois do tenant desativar o webhook — o único filtro
    // correto era o WHERE de WebhookService::dispatch(), que roda em SQL e por
    // isso não sofria o bug; a leitura da Entity para exibição, sim. Mesmo
    // padrão já aplicado em AccountIntegrationModel, IntegrationProviderModel
    // e IntegrationCommissionRuleModel — este model tinha ficado de fora.
    protected array $casts = [
        'id'         => 'integer',
        'account_id' => 'integer',
        'is_active'  => 'boolean',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
