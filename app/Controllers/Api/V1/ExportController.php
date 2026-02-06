<?php

namespace App\Controllers\Api\V1;

use App\Models\PropertyModel;

class ExportController extends BaseController
{
    /**
     * GET /api/v1/export/properties?format=csv|json
     * Exporta imóveis da conta autenticada
     */
    public function properties()
    {
        $accountId = $this->request->account_id ?? null;
        
        if (!$accountId) {
            return $this->failForbidden('Export requer autenticação com API Key vinculada a uma conta.');
        }

        $format = $this->request->getGet('format') ?? 'json';
         $filters = $this->request->getGet();

        // Query base
        $builder = model(PropertyModel::class)->where('account_id', $accountId);

        // Filtros opcionais
        if (!empty($filters['status'])) {
            $builder->where('status', $filters['status']);
        }
        
        if (!empty($filters['cidade'])) {
            $builder->like('cidade', $filters['cidade']);
        }

        if (!empty($filters['tipo_negocio'])) {
            $builder->where('tipo_negocio', $filters['tipo_negocio']);
        }

        if (!empty($filters['data_inicio'])) {
            $builder->where('created_at >=', $filters['data_inicio']);
        }

        if (!empty($filters['data_fim'])) {
            $builder->where('created_at <=', $filters['data_fim']);
        }

        $properties = $builder->findAll();

        if ($format === 'csv') {
            return $this->exportCSV($properties);
        }

        return $this->respondSuccess([
            'properties' => $properties,
            'total' => count($properties)
        ]);
    }

    /**
     * Gera arquivo CSV de imóveis
     */
    private function exportCSV(array $properties)
    {
        $filename = 'properties_export_' . date('Y-m-d_His') . '.csv';

        $this->response->setContentType('text/csv');
        $this->response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // Cabeçalho
        fputcsv($output, [
            'ID',
            'Título',
            'Descrição',
            'Tipo Negócio',
            'Tipo Imóvel',
            'Preço',
            'Cidade',
            'Bairro',
            'CEP',
            'Área Útil',
            'Quartos',
            'Banheiros',
            'Garagens',
            'Status',
            'Data Criação'
        ]);

        // Dados
        foreach ($properties as $prop) {
            fputcsv($output, [
                $prop->id,
                $prop->titulo,
                $prop->descricao,
                $prop->tipo_negocio,
                $prop->tipo_imovel,
                $prop->preco,
                $prop->cidade,
                $prop->bairro,
                $prop->cep,
                $prop->area_util,
                $prop->quartos,
                $prop->banheiros,
                $prop->garagens,
                $prop->status,
                $prop->created_at
            ]);
        }

        fclose($output);
        return $this->response;
    }
}
