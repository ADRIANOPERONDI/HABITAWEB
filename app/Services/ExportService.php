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
    /** Teto de linhas por exportação — evita estourar memória em conta grande. */
    public const MAX_ROWS = 5000;

    public function exportProperties(?int $accountId, array $filters, string $format): array
    {
        $data = $this->queryProperties($accountId, $filters)->findAll(self::MAX_ROWS);

        // external_id na primeira coluna: é a chave que o parceiro usa para
        // casar o registro exportado com o dele na hora de reimportar.
        $headers = ['external_id', 'ID', 'Título', 'Tipo', 'Negócio', 'Preço', 'Cidade', 'Bairro', 'Quartos', 'Área', 'Status', 'Criado em'];
        $rows = [];

        foreach ($data as $p) {
            $rows[] = [
                $p->external_id,
                $p->id,
                $p->titulo,
                $p->tipo_imovel,
                $p->tipo_negocio,
                number_format((float) $p->preco, 2, ',', '.'),
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
     * Exportação em JSON, para sincronização máquina-a-máquina.
     *
     * É a contrapartida do import: o parceiro puxa o que mudou no Habitaweb
     * (?updated_since=...) e reconcilia com a base dele pelo external_id,
     * fechando a via de mão dupla.
     *
     * @return array{properties: array, pagination: array}
     */
    public function exportPropertiesAsArray(int $accountId, array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $perPage = max(1, min($perPage, 500));
        $page    = max(1, $page);

        // Um único builder para contagem e página: chamar queryProperties() duas
        // vezes empilharia os mesmos WHEREs na instância compartilhada do model().
        // countAllResults(false) preserva a query para o findAll() seguinte.
        $builder = $this->queryProperties($accountId, $filters);
        $total   = $builder->countAllResults(false);

        $data = $builder->orderBy('id', 'ASC')->findAll($perPage, ($page - 1) * $perPage);

        $mediaModel = model(\App\Models\PropertyMediaModel::class);
        $properties = [];

        foreach ($data as $p) {
            $media = $mediaModel->where('property_id', $p->id)
                                ->orderBy('principal', 'DESC')
                                ->orderBy('ordem', 'ASC')
                                ->findAll();

            $properties[] = [
                'external_id'        => $p->external_id,
                'property_id'        => (int) $p->id,
                'titulo'             => $p->titulo,
                'descricao'          => $p->descricao,
                'tipo_negocio'       => $p->tipo_negocio,
                'tipo_imovel'        => $p->tipo_imovel,
                'preco'              => (float) $p->preco,
                'valor_condominio'   => $p->valor_condominio !== null ? (float) $p->valor_condominio : null,
                'iptu'               => $p->iptu !== null ? (float) $p->iptu : null,
                'area_total'         => $p->area_total !== null ? (float) $p->area_total : null,
                'area_construida'    => $p->area_construida !== null ? (float) $p->area_construida : null,
                'quartos'            => $p->quartos !== null ? (int) $p->quartos : null,
                'banheiros'          => $p->banheiros !== null ? (int) $p->banheiros : null,
                'suites'             => $p->suites !== null ? (int) $p->suites : null,
                'vagas'              => $p->vagas !== null ? (int) $p->vagas : null,
                'cep'                => $p->cep,
                'estado'             => $p->estado,
                'cidade'             => $p->cidade,
                'bairro'             => $p->bairro,
                'rua'                => $p->rua,
                'numero'             => $p->numero,
                'complemento'        => $p->complemento,
                'latitude'           => $p->latitude !== null ? (float) $p->latitude : null,
                'longitude'          => $p->longitude !== null ? (float) $p->longitude : null,
                'status'             => $p->status,
                'source'             => $p->source,
                'external_synced_at' => $p->external_synced_at,
                'created_at'         => (string) $p->created_at,
                'updated_at'         => (string) $p->updated_at,
                'images'             => array_map(static fn ($m) => [
                    'id'         => (int) $m->id,
                    'url'        => media_url($m->url),
                    'ordem'      => (int) $m->ordem,
                    'principal'  => (bool) $m->principal,
                    'source_url' => $m->source_url,
                ], $media),
            ];
        }

        return [
            'properties' => $properties,
            'pagination' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * Monta o builder de imóveis já escopado por conta e filtros.
     *
     * Instância NOVA de propósito: model() devolve a instância compartilhada, e
     * duas chamadas seguidas empilhariam os mesmos WHEREs na mesma query.
     */
    private function queryProperties(?int $accountId, array $filters): PropertyModel
    {
        $builder = new PropertyModel();

        if ($accountId) {
            $builder->where('account_id', $accountId);
        }

        if (!empty($filters['status'])) $builder->where('status', $filters['status']);
        if (!empty($filters['tipo_negocio'])) $builder->where('tipo_negocio', $filters['tipo_negocio']);
        if (!empty($filters['external_id'])) $builder->where('external_id', $filters['external_id']);

        // Sincronização incremental: só o que mudou desde a última passada.
        if (!empty($filters['updated_since'])) {
            $since = strtotime((string) $filters['updated_since']);
            if ($since !== false) {
                $builder->where('updated_at >=', date('Y-m-d H:i:s', $since));
            }
        }

        return $builder;
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
        
        $data = $builder->findAll(self::MAX_ROWS);
        
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
        
        $data = $builder->findAll(self::MAX_ROWS);
        
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
