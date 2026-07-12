<?php

namespace App\Controllers\Api\V1;

use App\Services\ExportService;

class ExportController extends BaseController
{
    protected ExportService $exportService;

    public function __construct()
    {
        $this->exportService = new ExportService();
    }

    /**
     * GET /api/v1/export/properties
     */
    public function properties()
    {
        return $this->handleExport('properties');
    }

    /**
     * GET /api/v1/export/leads
     */
    public function leads()
    {
        return $this->handleExport('leads');
    }

    /**
     * GET /api/v1/export/clients
     */
    public function clients()
    {
        return $this->handleExport('clients');
    }

    /**
     * Trata a lógica comum de exportação.
     */
    private function handleExport(string $type)
    {
        $accountId = $this->request->auth_account_id ?? null;
        
        if (!$accountId) {
            return $this->failForbidden('Export requer autenticação com API Key vinculada a uma conta.');
        }

        $format = $this->request->getGet('format') ?? 'csv';
        $filters = $this->request->getGet();

        try {
            $method = 'export' . ucfirst($type);
            
            if (!method_exists($this->exportService, $method)) {
                return $this->respondError("Tipo de exportação '$type' não disponível.", 400);
            }

            $result = $this->exportService->$method($accountId, $filters, $format);

            return $this->response
                ->setContentType($result['content_type'])
                ->setHeader('Content-Disposition', 'attachment; filename="' . $result['filename'] . '"')
                ->setBody(file_get_contents($result['file_path']));

        } catch (\Exception $e) {
            // Detalhe só no log do servidor; ao cliente, mensagem genérica (evita vazar
            // SQL/caminhos/detalhes internos na resposta da API).
            log_message('error', '[API Export] ' . $e->getMessage());
            return $this->respondError('Erro ao exportar dados. Tente novamente ou contate o suporte.', 500);
        }
    }
}

