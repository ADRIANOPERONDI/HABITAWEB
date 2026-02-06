<?php

namespace App\Controllers\Webhook;

use App\Controllers\BaseController;
use App\Services\PaymentService;
use App\Services\WebhookService;
use App\Models\WebhookLogModel;

class WebhookController extends BaseController
{
    protected $paymentService;
    protected $webhookService;
    protected $webhookLogModel;

    public function __construct()
    {
        $this->paymentService = new PaymentService();
        $this->webhookService = new WebhookService();
        $this->webhookLogModel = model(WebhookLogModel::class);
    }

    /**
     * Unified endpoint to receive webhooks from any gateway.
     * Route: /webhook/(:segment)
     * 
     * @param string $gatewayCode The code of the gateway (asaas, stripe, mercadopago)
     */
    public function receive(string $gatewayCode)
    {
        // 1. Load the gateway
        try {
            $this->paymentService->setGateway($gatewayCode);
        } catch (\Exception $e) {
            log_message('error', "WebhookController: Gateway '$gatewayCode' not found.");
            return $this->response->setStatusCode(404)->setJSON(['error' => 'Gateway not found']);
        }

        // 2. Get Raw Payload
        $rawPayload = file_get_contents('php://input');
        $payload = json_decode($rawPayload, true);

        if (!$payload) {
            log_message('error', "WebhookController: Invalid payload from $gatewayCode.");
            return $this->response->setStatusCode(400)->setJSON(['error' => 'Invalid payload']);
        }

        // 3. Log Webhook
        $logId = $this->webhookLogModel->logWebhook(
            $payload['event'] ?? $payload['type'] ?? 'UNKNOWN',
            $payload['id'] ?? null,
            $payload
        );

        // 4. Handle Gateway-specific normalization
        // This uses the GatewayInterface->handleWebhook implemented in each gateway
        try {
            // Some gateways might need headers for validation (signatures)
            // We pass the raw body if needed, but here we pass the decoded array for consistency
            $normalized = $this->paymentService->getActiveGateway()->handleWebhook($payload);
            
            // 5. Process business logic via WebhookService
            if ($this->webhookService->processEvent($gatewayCode, $normalized)) {
                $this->webhookLogModel->markAsProcessed($logId);
                return $this->response->setJSON(['success' => true]);
            }

            return $this->response->setStatusCode(200)->setJSON(['success' => false, 'message' => 'Processed with no action']);

        } catch (\Exception $e) {
            log_message('error', "WebhookController Error ($gatewayCode): " . $e->getMessage());
            $this->webhookLogModel->markAsProcessed($logId, $e->getMessage());
            
            // We usually return 200/201 to the gateway to stop retries if it's a logic error,
            // but for infrastructure/unknown errors we might want to let them retry.
            return $this->response->setStatusCode(500)->setJSON(['error' => $e->getMessage()]);
        }
    }

    /**
     * Optional: Legacy support for Asaas route or specific aliases
     */
    public function asaas()
    {
        return $this->receive('asaas');
    }
}
