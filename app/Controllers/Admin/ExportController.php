<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\ExportService;

class ExportController extends BaseController
{
    protected ExportService $exportService;

    public function __construct()
    {
        $this->exportService = new ExportService();
    }

    /**
     * Exporta imóveis.
     */
    public function properties()
    {
        return $this->handleExport('properties');
    }

    /**
     * Exporta Leads.
     */
    public function leads()
    {
        return $this->handleExport('leads');
    }

    /**
     * Exporta Clientes.
     */
    public function clients()
    {
        return $this->handleExport('clients');
    }

    /**
     * Trata a lógica de exportação para as rotas administrativas.
     */
    private function handleExport(string $type)
    {
        $user = auth()->user();
        $accountId = $user->account_id;
        $isGlobalAdmin = $user->inGroup('superadmin', 'admin');

        // Se for admin global e houver account_id no GET, sobrescreve.
        // Isso permite ao superadmin exportar dados de uma conta específica.
        if ($isGlobalAdmin) {
            $getAccountId = $this->request->getGet('account_id');
            if ($getAccountId !== null) {
                // Se account_id for "all" ou vazio (e for superadmin), exporta tudo.
                $accountId = ($getAccountId === '' || $getAccountId === 'all') ? null : (int)$getAccountId;
            }
        }

        if (!$accountId && !$isGlobalAdmin) {
            return redirect()->back()->with('error', 'Permissão negada ou conta não identificada.');
        }

        $format = $this->request->getGet('format') ?? 'csv';
        $filters = $this->request->getGet();

        try {
            $method = 'export' . ucfirst($type);
            
            if (!method_exists($this->exportService, $method)) {
                return redirect()->back()->with('error', "Tipo de exportação '$type' não disponível.");
            }

            $result = $this->exportService->$method($accountId, $filters, $format);

            return $this->response
                ->setContentType($result['content_type'])
                ->setHeader('Content-Disposition', 'attachment; filename="' . $result['filename'] . '"')
                ->setBody(file_get_contents($result['file_path']));

        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Erro ao exportar dados: ' . $e->getMessage());
        }
    }
}
