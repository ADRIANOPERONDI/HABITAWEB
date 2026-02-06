<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class LeadsController extends BaseController
{
    public function index()
    {
        $service = service('leadService');
        $user = auth()->user();
        $filters = [];
        
        // Check admin once
        $isAdmin = $user->inGroup('superadmin', 'admin');
        
        // Se NÃO for superadmin, filtra pela conta
        if (!$isAdmin) { 
            // FIXME 'admin' group usually is the "imobiliaria admin", superadmin is "master"
            // Assuming 'superadmin' is the master key.
            if ($user->account_id) {
                $filters['account_id_anunciante'] = $user->account_id;
            } else {
                 // Usuario sem conta e sem ser superadmin -> nao vê nada
                 return view('admin/leads/index', ['leads' => [], 'pager' => \Config\Services::pager(), 'isAdmin' => false]);
            }
        }

        $data = $service->listLeads($filters, 20);

        return view('admin/leads/index', [
            'leads' => $data['leads'],
            'pager' => $data['pager'],
            'isAdmin' => $isAdmin
        ]);
    }

    /**
     * Retorna detalhes do lead via AJAX para o modal
     */
    public function show($id)
    {
        $service = service('leadService');
        $data = $service->getLeadWithEvents((int)$id);

        if (empty($data)) {
            return $this->response->setJSON(['success' => false, 'message' => 'Lead não encontrado.'])->setStatusCode(404);
        }

        return $this->response->setJSON([
            'success'  => true,
            'lead'     => $data['lead'],
            'events'   => $data['events'],
            'property' => $data['property']
        ]);
    }

    /**
     * Atualiza status do lead via AJAX
     */
    public function updateStatus($id)
    {
        $status = $this->request->getPost('status');
        $service = service('leadService');
        
        // Security: Verificação de conta...
        // TODO: Adicionar check se o lead pertence à conta do usuário logado se não for admin.
        
        if ($service->updateStatus($id, $status)) {
            return $this->response->setJSON(['success' => true, 'message' => 'Status atualizado.']);
        }
        
        return $this->response->setJSON(['success' => false, 'message' => 'Erro ao atualizar status.']);
    }

    /**
     * Atualiza dados básicos do lead via AJAX
     */
    public function update($id)
    {
        $service = service('leadService');
        
        // Security: Verificação de conta TODO
        
        $fields = ['nome_visitante', 'email_visitante', 'telefone_visitante'];
        $updateData = [];
        foreach ($fields as $field) {
            if ($this->request->getPost($field) !== null) {
                $updateData[$field] = $this->request->getPost($field);
            }
        }

        if ($service->updateLead((int)$id, $updateData)) {
            return $this->response->setJSON(['success' => true, 'message' => 'Lead atualizado com sucesso.']);
        }

        return $this->response->setJSON(['success' => false, 'message' => 'Erro ao atualizar lead.'])->setStatusCode(400);
    }
}
