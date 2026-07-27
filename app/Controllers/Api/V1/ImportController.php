<?php

namespace App\Controllers\Api\V1;

use App\Services\PropertyImportService;

/**
 * Entrada do catálogo do parceiro no Habitaweb.
 *
 * POST /api/v1/import/properties aceita dois formatos, decididos pelo
 * Content-Type da requisição:
 *   - application/json   → lote de imóveis, com imagens por URL (recomendado)
 *   - multipart/form-data → arquivo CSV (compatibilidade)
 *
 * Em ambos, external_id é a chave de sincronização: reenviar o mesmo item
 * ATUALIZA o imóvel em vez de duplicá-lo.
 */
class ImportController extends BaseController
{
    protected PropertyImportService $importService;

    public function __construct()
    {
        $this->importService = new PropertyImportService();
    }

    /**
     * POST /api/v1/import/properties
     */
    public function properties()
    {
        $accountId = $this->currentAccountId();

        if (! $accountId) {
            return $this->respondForbidden('Import requer autenticação vinculada a uma conta.');
        }

        return $this->isJsonRequest()
            ? $this->importJson($accountId)
            : $this->importCsv($accountId);
    }

    /**
     * Lote em JSON. Aceita tanto {"properties": [...]} quanto um array puro.
     */
    private function importJson(int $accountId)
    {
        $body = $this->getJsonBody();

        if ($body === null) {
            return $this->respondInvalidJson();
        }

        $items        = $body['properties'] ?? $body['items'] ?? $body['data'] ?? null;
        $validateOnly = $this->truthy($body['validate_only'] ?? false);

        // Array puro no corpo raiz também é aceito.
        if ($items === null && array_is_list($body)) {
            $items = $body;
        }

        if (! is_array($items) || $items === []) {
            return $this->respondError(
                'Envie os imóveis em "properties": [ ... ] (lista não vazia).',
                400,
                [],
                self::ERR_INVALID_PAYLOAD
            );
        }

        if (count($items) > PropertyImportService::MAX_JSON_ITEMS) {
            return $this->respondError(
                sprintf(
                    'Máximo de %d imóveis por requisição (recebidos %d). Divida em lotes.',
                    PropertyImportService::MAX_JSON_ITEMS,
                    count($items)
                ),
                422,
                [],
                self::ERR_VALIDATION
            );
        }

        $outcome = $this->importService->importJson($accountId, $items, $validateOnly);

        return $this->respondImport($outcome, $validateOnly, 'json');
    }

    /**
     * Arquivo CSV via multipart.
     */
    private function importCsv(int $accountId)
    {
        $file         = $this->request->getFile('file');
        $validateOnly = $this->truthy($this->request->getPost('validate_only') ?? false);

        if (! $file || ! $file->isValid()) {
            return $this->respondError(
                'Envie o arquivo no campo "file" (multipart/form-data) ou use Content-Type: application/json.',
                400,
                [],
                self::ERR_INVALID_PAYLOAD
            );
        }

        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->respondError('Arquivo muito grande. Máximo: 5MB.', 413, [], self::ERR_VALIDATION);
        }

        // Checagem por conteúdo real, não pelo Content-Type declarado pelo cliente.
        // O filtro anterior exigia exatamente 'text/csv' vindo do cliente, o que
        // rejeitava Excel/Windows (application/vnd.ms-excel, text/plain,
        // application/octet-stream) — era restritivo demais e, ao mesmo tempo,
        // não era verificação nenhuma, já que o cliente controla esse cabeçalho.
        if (! $this->looksLikeCsv($file->getTempName())) {
            return $this->respondError(
                'O arquivo enviado não parece ser um CSV de texto.',
                400,
                [],
                self::ERR_INVALID_PAYLOAD
            );
        }

        $outcome = $this->importService->importCsv($accountId, $file->getTempName(), $validateOnly);

        if (isset($outcome['fatal'])) {
            return $this->respondError($outcome['fatal'], 400, [], self::ERR_INVALID_PAYLOAD);
        }

        return $this->respondImport($outcome, $validateOnly, 'csv');
    }

    /**
     * Resposta única para os dois formatos.
     *
     * 207 (Multi-Status) quando parte dos itens falhou: o parceiro precisa
     * distinguir "tudo certo" de "importou 8 de 10" sem ter de contar o array.
     */
    private function respondImport(array $outcome, bool $validateOnly, string $format)
    {
        $summary = $outcome['summary'];
        $status  = ($summary['errors'] > 0 && $summary['errors'] < $summary['total']) ? 207 : 200;

        if ($summary['total'] > 0 && $summary['errors'] === $summary['total']) {
            $status = 422;
        }

        return $this->respondSuccess(
            [
                'format'        => $format,
                'validate_only' => $validateOnly,
                'summary'       => $summary,
                'results'       => $outcome['results'],
            ],
            $validateOnly ? 'Validação concluída.' : 'Importação concluída.',
            $status
        );
    }

    /**
     * Heurística leve de CSV: precisa ser texto legível e ter delimitador na
     * primeira linha. Não usamos finfo puro porque CSV é detectado como
     * text/plain e, às vezes, application/csv.
     */
    private function looksLikeCsv(string $path): bool
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return false;
        }

        $firstLine = fgets($handle, 8192);
        fclose($handle);

        if ($firstLine === false || trim($firstLine) === '') {
            return false;
        }

        // Bytes de controle (exceto tab/CR/LF) indicam binário disfarçado.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $firstLine)) {
            return false;
        }

        return str_contains($firstLine, ',') || str_contains($firstLine, ';') || str_contains($firstLine, "\t");
    }

    private function truthy($value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'TRUE', 'on', 'yes'], true);
    }
}
