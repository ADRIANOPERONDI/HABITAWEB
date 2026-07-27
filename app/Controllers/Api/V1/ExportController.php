<?php

namespace App\Controllers\Api\V1;

use App\Services\ExportService;

class ExportController extends BaseController
{
    /** Formatos aceitos em ?format=. */
    private const FORMATS = ['csv', 'json', 'xls', 'xlsx', 'excel', 'pdf'];

    protected ExportService $exportService;

    public function __construct()
    {
        $this->exportService = new ExportService();
    }

    /**
     * GET /api/v1/export/properties
     *
     * Com ?format=json vira a contrapartida do import: devolve os imóveis com
     * external_id, imagens e paginação, para o parceiro reconciliar a base dele.
     * Aceita ?updated_since=<ISO8601> para sincronização incremental.
     */
    public function properties()
    {
        $accountId = $this->currentAccountId();

        if (! $accountId) {
            return $this->respondForbidden('Export requer autenticação vinculada a uma conta.');
        }

        $format = strtolower((string) ($this->request->getGet('format') ?? 'csv'));

        if (! in_array($format, self::FORMATS, true)) {
            return $this->respondError(
                'format deve ser um de: ' . implode(', ', self::FORMATS) . '.',
                422,
                [],
                self::ERR_VALIDATION
            );
        }

        if ($format === 'json') {
            $filters = array_intersect_key(
                $this->request->getGet(),
                array_flip(['status', 'tipo_negocio', 'external_id', 'updated_since'])
            );

            try {
                return $this->respondSuccess($this->exportService->exportPropertiesAsArray(
                    $accountId,
                    $filters,
                    (int) ($this->request->getGet('page') ?? 1),
                    (int) ($this->request->getGet('per_page') ?? 100)
                ));
            } catch (\Throwable $e) {
                log_message('error', '[API Export json] ' . $e->getMessage());

                return $this->respondError('Erro ao exportar dados.', 500, [], self::ERR_INTERNAL);
            }
        }

        return $this->handleFileExport('properties', $format);
    }

    /**
     * GET /api/v1/export/leads
     */
    public function leads()
    {
        return $this->guardedFileExport('leads');
    }

    /**
     * GET /api/v1/export/clients
     */
    public function clients()
    {
        return $this->guardedFileExport('clients');
    }

    private function guardedFileExport(string $type)
    {
        if (! $this->currentAccountId()) {
            return $this->respondForbidden('Export requer autenticação vinculada a uma conta.');
        }

        $format = strtolower((string) ($this->request->getGet('format') ?? 'csv'));

        if (! in_array($format, self::FORMATS, true) || $format === 'json') {
            return $this->respondError(
                'format deve ser um de: csv, xls, xlsx, excel, pdf.',
                422,
                [],
                self::ERR_VALIDATION
            );
        }

        return $this->handleFileExport($type, $format);
    }

    /**
     * Exportação em arquivo (download).
     */
    private function handleFileExport(string $type, string $format)
    {
        $accountId = $this->currentAccountId();
        $filters   = $this->request->getGet();

        try {
            $method = 'export' . ucfirst($type);

            if (! method_exists($this->exportService, $method)) {
                return $this->respondError("Tipo de exportação '{$type}' não disponível.", 400, [], self::ERR_VALIDATION);
            }

            $result = $this->exportService->{$method}($accountId, $filters, $format);

            return $this->response
                ->setContentType($result['content_type'])
                ->setHeader('Content-Disposition', 'attachment; filename="' . $result['filename'] . '"')
                ->setBody(file_get_contents($result['file_path']));
        } catch (\Throwable $e) {
            // Detalhe só no log do servidor; ao cliente, mensagem genérica (evita vazar
            // SQL/caminhos/detalhes internos na resposta da API).
            log_message('error', '[API Export] ' . $e->getMessage());

            return $this->respondError(
                'Erro ao exportar dados. Tente novamente ou contate o suporte.',
                500,
                [],
                self::ERR_INTERNAL
            );
        }
    }
}
