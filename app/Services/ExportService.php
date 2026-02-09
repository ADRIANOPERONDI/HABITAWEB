<?php

namespace App\Services;

use App\Models\PropertyModel;
use App\Models\LeadModel;
use App\Models\ClientModel;
use Dompdf\Dompdf;
use Dompdf\Options;

class ExportService
{
    /**
     * Exporta imóveis.
     */
    public function exportProperties(?int $accountId, array $filters, string $format): array
    {
        $model = model(PropertyModel::class);
        $builder = $model;
        if ($accountId) {
            $builder->where('account_id', $accountId);
        }
        
        // Aplicação de filtros básicos (conforme ExportController original)
        if (!empty($filters['status'])) $builder->where('status', $filters['status']);
        if (!empty($filters['tipo_negocio'])) $builder->where('tipo_negocio', $filters['tipo_negocio']);
        
        $data = $builder->findAll();
        
        $headers = ['ID', 'Título', 'Tipo', 'Negócio', 'Preço', 'Cidade', 'Bairro', 'Quartos', 'Área', 'Status', 'Criado em'];
        $rows = [];
        
        foreach ($data as $p) {
            $rows[] = [
                $p->id,
                $p->titulo,
                $p->tipo_imovel,
                $p->tipo_negocio,
                number_format($p->preco, 2, ',', '.'),
                $p->cidade,
                $p->bairro,
                $p->quartos,
                $p->area_total,
                $p->status,
                $p->created_at
            ];
        }

        return $this->generateFile($headers, $rows, $format, 'imoveis_export');
    }

    /**
     * Exporta Leads.
     */
    public function exportLeads(?int $accountId, array $filters, string $format): array
    {
        $model = model(LeadModel::class);
        $builder = $model;
        if ($accountId) {
            $builder->where('account_id_anunciante', $accountId);
        }
        
        if (!empty($filters['status'])) $builder->where('status', $filters['status']);
        
        $data = $builder->findAll();
        
        $headers = ['ID', 'Visitante', 'E-mail', 'Telefone', 'Imóvel ID', 'Status', 'Origem', 'Criado em'];
        $rows = [];
        
        foreach ($data as $l) {
            $rows[] = [
                $l->id,
                $l->nome_visitante,
                $l->email_visitante,
                $l->telefone_visitante,
                $l->property_id,
                $l->status,
                $l->origem,
                $l->created_at
            ];
        }

        return $this->generateFile($headers, $rows, $format, 'leads_export');
    }

    /**
     * Exporta Clientes.
     */
    public function exportClients(?int $accountId, array $filters, string $format): array
    {
        $model = model(ClientModel::class);
        $builder = $model;
        if ($accountId) {
            $builder->where('account_id', $accountId);
        }
        
        $data = $builder->findAll();
        
        $headers = ['ID', 'Nome', 'E-mail', 'Telefone', 'Documento', 'Tipo', 'Criado em'];
        $rows = [];
        
        foreach ($data as $c) {
            $rows[] = [
                $c->id,
                $c->nome,
                $c->email,
                $c->telefone,
                $c->cpf_cnpj,
                $c->tipo_cliente,
                $c->created_at
            ];
        }

        return $this->generateFile($headers, $rows, $format, 'clientes_export');
    }

    /**
     * Orquestra a geração do arquivo baseado no formato.
     */
    private function generateFile(array $headers, array $rows, string $format, string $prefix): array
    {
        $filename = $prefix . '_' . date('Y-m-d_His');
        $filePath = '';
        $contentType = '';

        switch ($format) {
            case 'csv':
                $filePath = $this->toCSV($headers, $rows);
                $contentType = 'text/csv';
                $filename .= '.csv';
                break;
            case 'xls':
            case 'xlsx':
            case 'excel':
                $filePath = $this->toExcel($headers, $rows);
                $contentType = 'application/vnd.ms-excel';
                $filename .= '.xls';
                break;
            case 'pdf':
                $filePath = $this->toPDF($headers, $rows, $prefix);
                $contentType = 'application/pdf';
                $filename .= '.pdf';
                break;
            default:
                throw new \Exception("Formato de exportação '$format' não suportado.");
        }

        return [
            'file_path'    => $filePath,
            'filename'     => $filename,
            'content_type' => $contentType
        ];
    }

    private function toCSV(array $headers, array $rows): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'exp');
        $handle = fopen($tempFile, 'w');
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM
        fputcsv($handle, $headers, ';');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';');
        }
        fclose($handle);
        return $tempFile;
    }

    private function toExcel(array $headers, array $rows): string
    {
        $html = '<table border="1"><thead><tr>';
        foreach ($headers as $h) $html .= '<th style="background:#ddd">'.htmlspecialchars($h).'</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) $html .= '<td>'.htmlspecialchars((string)$cell).'</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        
        $tempFile = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($tempFile, $html);
        return $tempFile;
    }

    private function toPDF(array $headers, array $rows, string $title): string
    {
        $html = '<html><head><style>
            body { font-family: sans-serif; font-size: 10px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #ccc; padding: 5px; text-align: left; }
            th { background: #eee; }
            h1 { font-size: 16px; color: #333; }
        </style></head><body>';
        $html .= '<h1>Relatório de ' . ucfirst(str_replace('_export', '', $title)) . '</h1>';
        $html .= '<p>Gerado em: ' . date('d/m/Y H:i') . '</p>';
        $html .= '<table><thead><tr>';
        foreach ($headers as $h) $html .= '<th>'.$h.'</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) $html .= '<td>'.$cell.'</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        
        $tempFile = tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($tempFile, $dompdf->output());
        return $tempFile;
    }
}
