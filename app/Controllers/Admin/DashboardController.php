<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class DashboardController extends BaseController
{
    public function index()
    {
        $user = auth()->user();
        
        $isAdmin = $user->inGroup('superadmin', 'admin');
        
        // CRITICAL: Non-admin MUST have account_id
        if (!$isAdmin && !$user->account_id) {
            return redirect()->to('admin')->with('error', 'Sua conta está com problema. Contate o suporte ou crie uma nova conta.');
        }
        
        $accountId = $user->account_id ?? 1; // Only admins can have null

        // Scoping por corretor (Equipe)
        $isBroker = $user->inGroup('imobiliaria_corretor') && !$user->inGroup('imobiliaria_admin');
        $brokerId = $isBroker ? $user->id : null;

        // FILTROS do Request
        $filters = [
            'bairro'     => $this->request->getGet('bairro'),
            'condominio' => $this->request->getGet('condominio'),
        ];

        // Chamada ao Service (Controller -> Service -> Model)
        $dashboardService = new \App\Services\DashboardService();
        $data = $dashboardService->getDashboardData($accountId, $filters, $brokerId, $isAdmin);

        // Merge filters para a view manter o estado dos selects
        $data['filters'] = $filters;

        return view('admin/dashboard', $data);
    }
}
