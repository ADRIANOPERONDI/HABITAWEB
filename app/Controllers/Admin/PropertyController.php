<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\PropertyService;

class PropertyController extends BaseController
{
    protected PropertyService $propertyService;
    protected \App\Services\ClientService $clientService;

    public function __construct()
    {
        $this->propertyService = new PropertyService();
        $this->clientService = new \App\Services\ClientService();
    }

    /**
     * Lista imóveis com filtros
     */
    public function index()
    {
        $filters = [
            'status' => $this->request->getGet('status'),
            'account_type' => $this->request->getGet('account_type'),
            'term'   => $this->request->getGet('term'),
            'show_deleted' => $this->request->getGet('view') === 'deleted'
        ];

        $user = auth()->user();
        $isAdmin = $user->inGroup('superadmin', 'admin');
        
        $accounts = [];
        if ($isAdmin) {
            $filters['account_id'] = $this->request->getGet('account_id');
            // Carrega contas via service
            $accountService = new \App\Services\AccountService();
            $accounts = $accountService->getAllAccountsSortedByName();
        } elseif ($user->account_id) {
            $filters['account_id'] = $user->account_id;
            
            if ($user->inGroup('imobiliaria_corretor') && !$user->inGroup('imobiliaria_admin')) {
                $filters['user_id_responsavel'] = $user->id;
            }
        } else {
            $filters['account_id'] = -1;
        }

        if (empty($filters['status'])) {
            $filters['status'] = 'ALL';
        }

        $data = $this->propertyService->listProperties($filters);
        
        $destaqueStats = null;
        if ($user->account_id) {
            $destaqueStats = $this->propertyService->canMarkAsDestaque($user->account_id);
        }

        return view('Admin/Properties/index', [
            'properties' => $data['properties'],
            'pager'      => $data['pager'],
            'filters'    => $filters,
            'accounts'   => $accounts,
            'currentView' => $this->request->getGet('view') ?? 'active',
            'destaqueStats' => $destaqueStats
        ]);
    }

    public function new()
    {
        $user = auth()->user();
        $brokers = $user->account_id ? $this->propertyService->getBrokers($user->account_id) : [];

        return view('Admin/Properties/form', [
            'property' => null,
            'clients'  => [],
            'brokers'  => $brokers
        ]);
    }

    public function create()
    {
        $data = $this->request->getPost();
        
        $user = auth()->user();
        if ($user && $user->account_id) {
            $data['account_id'] = $user->account_id;
        }

        if (!empty($data['is_destaque'])) {
            $check = $this->propertyService->canMarkAsDestaque($data['account_id']);
            if (!$check['allowed']) {
                if ($this->request->isAJAX()) {
                    return $this->response->setJSON(['success' => false, 'message' => $check['message']]);
                }
                return redirect()->back()->withInput()->with('error', $check['message']);
            }
        }

        $result = $this->propertyService->trySaveProperty($data);

        if ($this->request->isAJAX()) {
            if ($result['success']) {
                $id = $result['data']->id ?? null;
                return $this->response->setJSON([
                    'success' => true, 
                    'message' => $result['message'],
                    'id'      => $id,
                    'redirect' => ($data['status'] ?? '') === 'ACTIVE' ? site_url('admin/properties') : null
                ]);
            }
            return $this->response->setJSON(['success' => false, 'errors' => $result['errors'], 'message' => 'Erro ao salvar imóvel']);
        }

        if ($result['success']) {
            return redirect()->to('admin/properties')->with('message', $result['message']);
        }

        return redirect()->back()->withInput()->with('errors', $result['errors']);
    }

    public function edit($id)
    {
        $user = auth()->user();
        $details = $this->propertyService->getPropertyDetails($id);

        if (!$details || !isset($details['property'])) {
            return redirect()->back()->with('error', 'Imóvel não encontrado');
        }

        $property = $details['property'];

        $isAdmin = $user->inGroup('superadmin', 'admin');
        
        if (!$isAdmin) {
            if (!$user->account_id || $property->account_id != $user->account_id) {
                 return redirect()->back()->with('error', 'Acesso negado a este imóvel');
            }
        }

        if ($user->inGroup('imobiliaria_corretor') && !$user->inGroup('imobiliaria_admin')) {
            if ($property->user_id_responsavel != $user->id) {
                 return redirect()->back()->with('error', 'Você só pode editar seus próprios imóveis');
            }
        }

        $brokers = $user->account_id ? $this->propertyService->getBrokers($user->account_id) : [];

        $clients = [];
        if (!empty($property->client_id)) {
            $client = $this->clientService->getClient($property->client_id, $property->account_id);
            if ($client) {
                $clients[] = $client;
            }
        }

        return view('Admin/Properties/form', [
            'property' => $property,
            'clients'  => $clients,
            'brokers'  => $brokers
        ]);
    }

    public function update($id)
    {
        log_message('emergency', '[PropertyController] Update entry for ID: ' . $id);
        
        // Uso do service para buscar os detalhes e verificação de segurança inclusa
        $details = $this->propertyService->getPropertyDetails($id);
        $property = $details['property'] ?? null;

        if (!$property) {
            return redirect()->back()->with('error', 'Imóvel não encontrado');
        }

        $user = auth()->user();
        $isAdmin = $user->inGroup('superadmin', 'admin');

        if (!$isAdmin) {
            if (!$user->account_id || $property->account_id != $user->account_id) {
                 return redirect()->back()->with('error', 'Acesso negado a este imóvel');
            }
        }

        $data = $this->request->getPost();
        unset($data['account_id']);

        $result = $this->propertyService->trySaveProperty($data, $id);
        log_message('emergency', '[PropertyController] Result from Service: ' . json_encode($result));

        if ($this->request->isAJAX()) {
            if ($result['success']) {
                return $this->response->setJSON([
                    'success' => true, 
                    'message' => $result['message'],
                    'redirect' => ($data['status'] ?? '') === 'ACTIVE' ? site_url('admin/properties') : null
                ]);
            }
            return $this->response->setJSON(['success' => false, 'errors' => $result['errors'], 'message' => 'Erro ao atualizar imóvel']);
        }

        if ($result['success']) {
             return redirect()->to('admin/properties')->with('message', $result['message']);
        }

        return redirect()->back()->withInput()->with('errors', $result['errors']);
    }

    public function delete($id)
    {
        $user = auth()->user();
        // Chamada ao service para buscar com deletados
        $property = $this->propertyService->getPropertyWithDeleted($id);

        if (!$property) {
            return $this->response->setJSON(['success' => false, 'message' => 'Imóvel não encontrado.']);
        }

        $isAdmin = $user->inGroup('superadmin', 'admin');
        if (!$isAdmin) {
            if (!$user->account_id || $property->account_id != $user->account_id) {
                return $this->response->setJSON(['success' => false, 'message' => 'Acesso negado.']);
            }
        }

        if ($this->propertyService->deleteProperty($id)) {
            return $this->response->setJSON(['success' => true, 'message' => 'Imóvel desativado com sucesso.']);
        }

        return $this->response->setJSON(['success' => false, 'message' => 'Erro ao desativar imóvel.']);
    }

    public function restore($id)
    {
        $user = auth()->user();
        // Chamada ao service para buscar apenas deletados
        $property = $this->propertyService->getPropertyOnlyDeleted($id);

        if (!$property) {
            return $this->response->setJSON(['success' => false, 'message' => 'Imóvel não encontrado ou já está ativo.']);
        }

        $isAdmin = $user->inGroup('superadmin', 'admin');
        if (!$isAdmin) {
             if (!$user->account_id || $property->account_id != $user->account_id) {
                return $this->response->setJSON(['success' => false, 'message' => 'Acesso negado.']);
            }
        }

        if ($this->propertyService->restoreProperty($id)) {
            return $this->response->setJSON(['success' => true, 'message' => 'Imóvel restaurado com sucesso!']);
        }

        return $this->response->setJSON(['success' => false, 'message' => 'Erro ao restaurar imóvel.']);
    }

    public function markAsClosed($id)
    {
        $user = auth()->user();
        // Service para buscar detalhes
        $details = $this->propertyService->getPropertyDetails($id);
        $property = $details['property'] ?? null;

        if (!$property) {
             return $this->response->setJSON(['success' => false, 'message' => 'Imóvel não encontrado.']);
        }

        $isAdmin = $user->inGroup('superadmin', 'admin');
        if (!$isAdmin) {
            if (!$user->account_id || $property->account_id != $user->account_id) {
                return $this->response->setJSON(['success' => false, 'message' => 'Acesso negado.']);
            }
        }

        $reason = $this->request->getPost('reason');
        $leadId = $this->request->getPost('lead_id');
        $value  = $this->request->getPost('closing_value');
        $notes  = $this->request->getPost('closing_notes');

        if (empty($reason)) {
             return $this->response->setJSON(['success' => false, 'message' => 'O motivo do fechamento é obrigatório.']);
        }

        $additionalData = [
            'closing_value' => $value,
            'closing_notes' => $notes
        ];

        if ($this->propertyService->markAsClosed($id, $reason, $leadId, $additionalData)) {
            return $this->response->setJSON(['success' => true, 'message' => 'Anúncio encerrado com sucesso!']);
        }

        return $this->response->setJSON(['success' => false, 'message' => 'Erro ao encerrar anúncio.']);
    }

    public function getLeadsForClosure($id)
    {
        $user = auth()->user();
        // Check ownership before listing leads
        $details = $this->propertyService->getPropertyDetails($id);
        $property = $details['property'] ?? null;

        if (!$property) {
            return $this->response->setJSON([]);
        }

        $isAdmin = $user->inGroup('superadmin', 'admin');
        if (!$isAdmin) {
            if (!$user->account_id || $property->account_id != $user->account_id) {
                return $this->response->setJSON([]); // Or error
            }
        }
        
        // Chamada ao service para buscar leads
        $leads = $this->propertyService->getLeadsForClosure((int)$id);

        return $this->response->setJSON($leads);
    }

    public function checkDestaqueLimit()
    {
        $id = $this->request->getGet('id');
        $user = auth()->user();
        
        if (!$user->account_id) {
            return $this->response->setJSON(['allowed' => false, 'message' => 'Conta não encontrada.']);
        }

        $check = $this->propertyService->canMarkAsDestaque($user->account_id, $id ? (int)$id : null);
        return $this->response->setJSON($check);
    }

    public function toggleDestaque($id)
    {
        $user = auth()->user();
        $details = $this->propertyService->getPropertyDetails($id);
        $property = $details['property'] ?? null;

        if (!$property) return $this->response->setJSON(['success' => false, 'message' => 'Imóvel não encontrado.']);

        $isAdmin = $user->inGroup('superadmin', 'admin');
        if (!$isAdmin) {
             if (!$user->account_id || $property->account_id != $user->account_id) {
                 return $this->response->setJSON(['success' => false, 'message' => 'Acesso negado.']);
             }
        }

        // Toggle state
        $newState = !$property->is_destaque;
        
        // Call Service
        $result = $this->propertyService->setPlanHighlight($id, $newState);
        
        return $this->response->setJSON($result);
    }
}
