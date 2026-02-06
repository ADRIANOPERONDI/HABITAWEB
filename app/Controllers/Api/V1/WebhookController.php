<?php

namespace App\Controllers\Api\V1;

use App\Models\IntegrationWebhookModel;
use App\Services\WebhookService;

class WebhookController extends BaseController
{
    protected IntegrationWebhookModel $webhookModel;
    protected WebhookService $webhookService;

    public function __construct()
    {
        $this->webhookModel = model(IntegrationWebhookModel::class);
        $this->webhookService = new WebhookService();
    }

    /**
     * GET /api/v1/webhooks
     * Lista webhooks da conta autenticada
     */
    public function index()
    {
        $accountId = $this->request->account_id ?? null;
        
        if (!$accountId) {
            return $this->failForbidden('Acesso restrito a contas autenticadas.');
        }

        $webhooks = $this->webhookModel->where('account_id', $accountId)
                                       ->orderBy('created_at', 'DESC')
                                       ->findAll();

        return $this->respondSuccess(['webhooks' => $webhooks]);
    }

    /**
     * GET /api/v1/webhooks/(:id)
     */
    public function show($id = null)
    {
        if (!$id) {
            return $this->respondError('ID do webhook é obrigatório.', 400);
        }

        $webhook = $this->webhookModel->find($id);
        
        if (!$webhook) {
            return $this->failNotFound('Webhook não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->account_id ?? null;
        if ($accountId && $webhook->account_id != $accountId) {
            return $this->failForbidden('Acesso negado a este webhook.');
        }

        return $this->respondSuccess(['webhook' => $webhook]);
    }

    /**
     * POST /api/v1/webhooks
     * Cria um novo webhook
     */
    public function create()
    {
        $accountId = $this->request->account_id ?? null;
        
        if (!$accountId) {
            return $this->failForbidden('Webhook requer autenticação com API Key vinculada a uma conta.');
        }

        $data = $this->request->getJSON(true);

        // Validação básica
        if (empty($data['name']) || empty($data['event']) || empty($data['target_url'])) {
            return $this->respondError('name, event e target_url são obrigatórios.', 400);
        }

        // Eventos permitidos
        $allowedEvents = ['lead.created', 'property.created', 'property.updated', 'subscription.expiring'];
        if (!in_array($data['event'], $allowedEvents)) {
            return $this->respondError('Evento inválido. Permitidos: ' . implode(', ', $allowedEvents), 400);
        }

        // Validar URL
        if (!filter_var($data['target_url'], FILTER_VALIDATE_URL)) {
            return $this->respondError('URL inválida.', 400);
        }

        $webhookData = [
            'account_id' => $accountId,
            'name' => $data['name'],
            'event' => $data['event'],
            'target_url' => $data['target_url'],
            'secret' => $data['secret'] ?? bin2hex(random_bytes(32)), // Gera secret se não fornecido
            'is_active' => $data['is_active'] ?? true,
        ];

        if ($this->webhookModel->insert($webhookData)) {
            $webhookId = $this->webhookModel->getInsertID();
            $webhook = $this->webhookModel->find($webhookId);
            
            return $this->respondCreated([
                'message' => 'Webhook criado com sucesso.',
                'webhook' => $webhook
            ]);
        }

        return $this->respondError('Erro ao criar webhook.', 500);
    }

    /**
     * PUT /api/v1/webhooks/(:id)
     * Atualiza um webhook
     */
    public function update($id = null)
    {
        if (!$id) {
            return $this->respondError('ID do webhook é obrigatório.', 400);
        }

        $webhook = $this->webhookModel->find($id);
        
        if (!$webhook) {
            return $this->failNotFound('Webhook não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->account_id ?? null;
        if ($accountId && $webhook->account_id != $accountId) {
            return $this->failForbidden('Acesso negado.');
        }

        $data = $this->request->getJSON(true);

        // Campos permitidos para atualização
        $updateData = [];
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['target_url'])) {
            if (!filter_var($data['target_url'], FILTER_VALIDATE_URL)) {
                return $this->respondError('URL inválida.', 400);
            }
            $updateData['target_url'] = $data['target_url'];
        }
        if (isset($data['is_active'])) $updateData['is_active'] = (bool)$data['is_active'];
        
        if ($this->webhookModel->update($id, $updateData)) {
            $webhook = $this->webhookModel->find($id);
            return $this->respondSuccess([
                'message' => 'Webhook atualizado com sucesso.',
                'webhook' => $webhook
            ]);
        }

        return $this->respondError('Erro ao atualizar webhook.', 500);
    }

    /**
     * DELETE /api/v1/webhooks/(:id)
     */
    public function delete($id = null)
    {
        if (!$id) {
            return $this->respondError('ID do webhook é obrigatório.', 400);
        }

        $webhook = $this->webhookModel->find($id);
        
        if (!$webhook) {
            return $this->failNotFound('Webhook não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->account_id ?? null;
        if ($accountId && $webhook->account_id != $accountId) {
            return $this->failForbidden('Acesso negado.');
        }

        if ($this->webhookModel->delete($id)) {
            return $this->respondSuccess(['message' => 'Webhook deletado com sucesso.']);
        }

        return $this->respondError('Erro ao deletar webhook.', 500);
    }

    /**
     * POST /api/v1/webhooks/(:id)/test
     * Testa um webhook enviando payload de exemplo
     */
    public function test($id = null)
    {
        if (!$id) {
            return $this->respondError('ID do webhook é obrigatório.', 400);
        }

        $webhook = $this->webhookModel->find($id);
        
        if (!$webhook) {
            return $this->failNotFound('Webhook não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->account_id ?? null;
        if ($accountId && $webhook->account_id != $accountId) {
            return $this->failForbidden('Acesso negado.');
        }

        // Payload de teste
        $testPayload = [
            'id' => 999,
            'message' => 'Este é um evento de teste do webhook',
            'test' => true,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        try {
            $this->webhookService->dispatch($webhook->event, $testPayload, $accountId);
            
            return $this->respondSuccess([
                'message' => 'Webhook de teste enviado com sucesso.',
                'target_url' => $webhook->target_url
            ]);
        } catch (\Exception $e) {
            return $this->respondError('Erro ao enviar teste: ' . $e->getMessage(), 500);
        }
    }
}
