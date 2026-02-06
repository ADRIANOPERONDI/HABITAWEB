<?php

namespace App\Models;

use CodeIgniter\Model;

class WebhookLogModel extends Model
{
    protected $table            = 'webhook_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'event_type', 'event_id', 'payload', 'processed', 'error_message', 'created_at'
    ];

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [
        'processed' => 'boolean',
        'payload' => 'json'
    ];

    // Dates
    protected $useTimestamps = false; // Desativado para evitar erro de sintaxe no Postgres com updatedField null
    protected $beforeInsert  = ['setCreatedDate'];

    protected function setCreatedDate(array $data)
    {
        $data['data']['created_at'] = date('Y-m-d H:i:s');
        return $data;
    }

    /**
     * Registrar webhook recebido
     */
    public function logWebhook($eventType, $eventId, $payload)
    {
        return $this->insert([
            'event_type' => $eventType,
            'event_id' => $eventId,
            'payload' => json_encode($payload),
            'processed' => false
        ]);
    }

    /**
     * Marcar como processado
     */
    public function markAsProcessed($id, $errorMessage = null)
    {
        return $this->update($id, [
            'processed' => true,
            'error_message' => $errorMessage
        ]);
    }

    /**
     * Buscar webhooks não processados
     */
    public function getUnprocessed($limit = 50)
    {
        return $this->where('processed', false)
                    ->orderBy('created_at', 'ASC')
                    ->limit($limit)
                    ->findAll();
    }
}
