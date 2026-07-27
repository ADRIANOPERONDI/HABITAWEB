<?php

namespace App\Controllers\Api\V1;

use App\Models\IntegrationWebhookModel;
use App\Services\WebhookService;

class WebhookController extends BaseController
{
    /** Eventos que podem ser assinados por webhook. */
    public const ALLOWED_EVENTS = [
        'lead.created',
        'property.created',
        'property.updated',
        'property.closed',
        'subscription.expiring',
    ];

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
        $accountId = $this->request->auth_account_id ?? null;
        
        if (!$accountId) {
            return $this->respondForbidden('Acesso restrito a contas autenticadas.');
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
            return $this->respondNotFound('Webhook não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->auth_account_id ?? null;
        if (!$accountId || $webhook->account_id != $accountId) {
            return $this->respondForbidden('Acesso negado a este webhook.');
        }

        return $this->respondSuccess(['webhook' => $webhook]);
    }

    /**
     * POST /api/v1/webhooks
     * Cria um novo webhook
     */
    public function create()
    {
        $accountId = $this->request->auth_account_id ?? null;
        
        if (!$accountId) {
            return $this->respondForbidden('Webhook requer autenticação vinculada a uma conta.');
        }

        $data = $this->getJsonBody();

        if ($data === null) {
            return $this->respondInvalidJson();
        }

        // Validação básica
        if (empty($data['name']) || empty($data['event']) || empty($data['target_url'])) {
            return $this->respondError('name, event e target_url são obrigatórios.', 400);
        }

        if (! in_array($data['event'], self::ALLOWED_EVENTS, true)) {
            return $this->respondError(
                'Evento inválido. Permitidos: ' . implode(', ', self::ALLOWED_EVENTS) . '.',
                422,
                [],
                self::ERR_VALIDATION
            );
        }

        // Validar URL. filter_var sozinho aceita esquemas exóticos e destinos
        // internos — o WebhookService dispara um cURL a partir do NOSSO servidor,
        // então uma target_url apontando para 127.0.0.1 ou 169.254.169.254
        // transformaria o webhook num proxy de SSRF.
        $urlCheck = (new \App\Libraries\Http\UrlGuard())->validate($data['target_url']);
        if (! $urlCheck['valid']) {
            return $this->respondError($urlCheck['message'], 422, [], self::ERR_VALIDATION);
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
            
            return $this->respondSuccess(['webhook' => $webhook], 'Webhook criado com sucesso.', 201);
        }

        return $this->respondError('Erro ao criar webhook.', 500, [], self::ERR_INTERNAL);
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
            return $this->respondNotFound('Webhook não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->auth_account_id ?? null;
        if (!$accountId || $webhook->account_id != $accountId) {
            return $this->respondForbidden('Acesso negado.');
        }

        $data = $this->getJsonBody();

        if ($data === null) {
            return $this->respondInvalidJson();
        }

        // Campos permitidos para atualização
        $updateData = [];
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['target_url'])) {
            $urlCheck = (new \App\Libraries\Http\UrlGuard())->validate($data['target_url']);
            if (! $urlCheck['valid']) {
                return $this->respondError($urlCheck['message'], 422, [], self::ERR_VALIDATION);
            }
            $updateData['target_url'] = $data['target_url'];
        }
        if (isset($data['is_active'])) $updateData['is_active'] = (bool)$data['is_active'];

        // 'event' era obrigatório na criação mas não podia ser alterado depois.
        if (isset($data['event'])) {
            if (! in_array($data['event'], self::ALLOWED_EVENTS, true)) {
                return $this->respondError(
                    'Evento inválido. Permitidos: ' . implode(', ', self::ALLOWED_EVENTS) . '.',
                    422,
                    [],
                    self::ERR_VALIDATION
                );
            }
            $updateData['event'] = $data['event'];
        }

        // Rotação do secret sob demanda.
        if (! empty($data['rotate_secret'])) {
            $updateData['secret'] = bin2hex(random_bytes(32));
        }

        // Corpo sem nenhum campo conhecido: update($id, []) lança
        // DataException("There is no data to update") do CI4 -> 500.
        if ($updateData === []) {
            return $this->respondError(
                'Nenhum campo atualizável foi enviado. Permitidos: name, target_url, event, is_active, rotate_secret.',
                422,
                [],
                self::ERR_VALIDATION
            );
        }

        if ($this->webhookModel->update($id, $updateData)) {
            $webhook = $this->webhookModel->find($id);
            return $this->respondSuccess(['webhook' => $webhook], 'Webhook atualizado com sucesso.');
        }

        return $this->respondError('Erro ao atualizar webhook.', 500, [], self::ERR_INTERNAL);
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
            return $this->respondNotFound('Webhook não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->auth_account_id ?? null;
        if (!$accountId || $webhook->account_id != $accountId) {
            return $this->respondForbidden('Acesso negado.');
        }

        if ($this->webhookModel->delete($id)) {
            return $this->respondSuccess(['webhook_id' => (int) $id], 'Webhook deletado com sucesso.');
        }

        return $this->respondError('Erro ao deletar webhook.', 500, [], self::ERR_INTERNAL);
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
            return $this->respondNotFound('Webhook não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->auth_account_id ?? null;
        if (!$accountId || $webhook->account_id != $accountId) {
            return $this->respondForbidden('Acesso negado.');
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
            log_message('error', '[API Webhook test] ' . $e->getMessage());
            return $this->respondError('Erro ao enviar o teste do webhook. Verifique a URL de destino e tente novamente.', 502, [], self::ERR_INTERNAL);
        }
    }
}
