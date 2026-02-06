<?php

namespace App\Controllers\Api\V1;

use App\Services\PropertyService;

class ImportController extends BaseController
{
    protected PropertyService $propertyService;

    public function __construct()
    {
        $this->propertyService = new PropertyService();
    }

    /**
     * POST /api/v1/import/properties
     * Importa imóveis via CSV
     */
    public function properties()
    {
        $accountId = $this->request->account_id ?? null;
        
        if (!$accountId) {
            return $this->failForbidden('Import requer autenticação com API Key vinculada a uma conta.');
        }

        $file = $this->request->getFile('file');
        $validateOnly = $this->request->getPost('validate_only') === 'true';

        if (!$file || !$file->isValid()) {
            return $this->respondError('Arquivo CSV inválido ou não enviado.', 400);
        }

        // Verifica se é CSV
        if ($file->getClientMimeType() !== 'text/csv') {
            return $this->respondError('Apenas arquivos CSV são aceitos.', 400);
        }

        // Limite de tamanho (ex: 5MB)
        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->respondError('Arquivo muito grande. Máximo: 5MB.', 400);
        }

        // Processa CSV
        $handle = fopen($file->getTempName(), 'r');
        $header = fgetcsv($handle); // Primeira linha = cabeçalho

        $expectedColumns = ['titulo', 'descricao', 'tipo_negocio', 'tipo_imovel', 'preco', 'cidade', 'bairro', 'cep'];
        
        if (!$this->validateCSVHeader($header, $expectedColumns)) {
            fclose($handle);
            return $this->respondError('Cabeçalho do CSV inválido. Esperado: ' . implode(', ', $expectedColumns), 400);
        }

        $results = [
            'total' => 0,
            'success' => 0,
            'errors' => 0,
            'details' => []
        ];

        $lineNumber = 1;
        $maxLines = 1000; // Limite de linhas por request

        while (($row = fgetcsv($handle)) !== false && $lineNumber <= $maxLines) {
            $lineNumber++;
            $results['total']++;

            try {
                $data = array_combine($header, $row);
                $data['account_id'] = $accountId;

                if ($validateOnly) {
                    // Apenas valida sem salvar
                    $validation = $this->propertyService->validatePropertyData($data);
                    if (!empty($validation['errors'])) {
                        throw new \Exception(implode(', ', $validation['errors']));
                    }
                    $results['success']++;
                    $results['details'][] = [
                        'line' => $lineNumber,
                        'status' => 'valid',
                        'data' => $data
                    ];
                } else {
                    // Salva no banco
                    $result = $this->propertyService->trySaveProperty($data);
                    
                    if ($result['success']) {
                        $results['success']++;
                        $results['details'][] = [
                            'line' => $lineNumber,
                            'status' => 'imported',
                            'property_id' => $result['property_id'] ?? null
                        ];
                    } else {
                        throw new \Exception($result['message']);
                    }
                }
            } catch (\Exception $e) {
                $results['errors']++;
                $results['details'][] = [
                    'line' => $lineNumber,
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }

        fclose($handle);

        return $this->respondSuccess([
            'message' => $validateOnly ? 'Validação concluída.' : 'Importação concluída.',
            'validate_only' => $validateOnly,
            'results' => $results
        ]);
    }

    /**
     * Valida se o cabeçalho do CSV contém as colunas esperadas
     */
    private function validateCSVHeader(array $header, array $expected): bool
    {
        $header = array_map('trim', array_map('strtolower', $header));
        $expected = array_map('strtolower', $expected);

        foreach ($expected as $col) {
            if (!in_array($col, $header)) {
                return false;
            }
        }

        return true;
    }
}
