<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\ClientService;

class ClientController extends BaseController
{
    protected ClientService $clientService;

    public function __construct()
    {
        $this->clientService = new ClientService();
    }

    public function index()
    {
        $user = auth()->user();
        $filters = [
            'term' => $this->request->getGet('term'),
            'account_id' => $this->request->getGet('account_id')
        ];

        // SuperAdmin/Admin master podem acessar sem conta vinculada
        $isAdmin = $user->inGroup('superadmin', 'admin');
        $accounts = [];

        if (!$user->account_id && !$isAdmin) {
             return redirect()->to('admin')->with('error', 'Você precisa de uma conta vinculada.');
        }

        if ($isAdmin) {
            $accounts = model('App\Models\AccountModel')->orderBy('nome', 'ASC')->findAll();
            $accountId = $filters['account_id']; // null = carrega de todos
        } else {
            $accountId = $user->account_id;
        }
        
        // Se for Admin master sem conta, listClients receberá null e trará todos.
        $clients = $this->clientService->listClients($accountId, $filters);

        return view('Admin/Clients/index', [
            'clients' => $clients,
            'filters' => $filters,
            'accounts' => $accounts,
            'isAdmin' => $isAdmin
        ]);
    }

    public function new()
    {
        $user = auth()->user();
        $isAdmin = $user->inGroup('superadmin', 'admin');
        $accounts = [];

        if ($isAdmin) {
            $accounts = model('App\Models\AccountModel')->orderBy('nome', 'ASC')->findAll();
        }

        return view('Admin/Clients/form', [
            'client' => null,
            'accounts' => $accounts,
            'isAdmin' => $isAdmin
        ]);
    }

    public function create()
    {
        $user = auth()->user();
        $data = $this->request->getPost();
        
        // Se não for admin ou não enviou account_id, força a conta do usuário
        $isAdmin = $user->inGroup('superadmin', 'admin');
        if (!$isAdmin || empty($data['account_id'])) {
            $data['account_id'] = $user->account_id;
        }

        $result = $this->clientService->saveClient($data);

        if ($result['success']) {
            return redirect()->to('admin/clients')->with('message', $result['message']);
        }

        return redirect()->back()->withInput()->with('errors', $result['errors']);
    }

    public function edit($id)
    {
        $user = auth()->user();
        $isAdmin = $user->inGroup('superadmin', 'admin');
        $accounts = [];

        // FAIL-SAFE: If not admin and no account_id, force invalid ID to prevent listing all.
        $accountId = $user->account_id;
        if (!$isAdmin && !$accountId) {
             $accountId = -1; 
        }

        // Se for admin, o service buscará sem filtro de conta
        $client = $this->clientService->getClient($id, $accountId);

        if (!$client) {
            return redirect()->to('admin/clients')->with('error', 'Cliente não encontrado.');
        }

        if ($isAdmin) {
             $accounts = model('App\Models\AccountModel')->orderBy('nome', 'ASC')->findAll();
        }

        return view('Admin/Clients/form', [
            'client' => $client,
            'accounts' => $accounts,
            'isAdmin' => $isAdmin
        ]);
    }

    public function update($id)
    {
        $user = auth()->user();
        // FAIL-SAFE: If not admin and no account_id, force invalid ID
        $isAdmin = $user->inGroup('superadmin', 'admin');
        $accountId = $user->account_id;
        if (!$isAdmin && !$accountId) {
             $accountId = -1; 
        }
        
        $client = $this->clientService->getClient($id, $accountId);

        if (!$client) {
             return redirect()->to('admin/clients')->with('error', 'Cliente não encontrado.');
        }

        $data = $this->request->getPost();
        
        // Se não for admin, impede mudança de conta (remove do array se vier)
        $isAdmin = $user->inGroup('superadmin', 'admin');
        if (!$isAdmin) {
            unset($data['account_id']);
        }
        
        $result = $this->clientService->saveClient($data, $id);

        if ($result['success']) {
            return redirect()->to('admin/clients')->with('message', $result['message']);
        }

        return redirect()->back()->withInput()->with('errors', $result['errors']);
    }

    /**
     * AJAX Quick Create for Property Form
     */
    public function quickCreate()
    {
        $user = auth()->user();
        $data = $this->request->getJSON(true);
        
        // Se não for admin ou não enviou account_id, força a conta do usuário
        $isAdmin = $user->inGroup('superadmin', 'admin');
        if (!$isAdmin || empty($data['account_id'])) {
            $data['account_id'] = $user->account_id;
        }

        // Tipo padrão se não enviado
        if (empty($data['tipo_cliente'])) {
            $data['tipo_cliente'] = 'PROPRIETARIO';
        }

        $result = $this->clientService->saveClient($data);

        if ($result['success']) {
            return $this->response->setJSON([
                'success' => true,
                'message' => 'Cliente cadastrado com sucesso.',
                'client'  => [
                    'id'   => $result['id'],
                    'nome' => $data['nome']
                ]
            ]);
        }

        return $this->response->setJSON([
            'success' => false,
            'message' => 'Erro ao cadastrar cliente.',
            'errors'  => $result['errors']
        ]);
    }

    /**
     * AJAX Search for Select2
     */
    public function search()
    {
        $user = auth()->user();
        $isAdmin = $user->inGroup('superadmin', 'admin');

        if (!$user->account_id && !$isAdmin) {
             return $this->response->setJSON(['results' => []]);
        }

        $term = $this->request->getGet('term');
        $accountId = $user->account_id;

        $clients = $this->clientService->listClients($accountId, ['term' => $term]);

        $results = [];
        foreach ($clients as $client) {
            $displayText = trim($client->nome);
            $documento = trim($client->cpf_cnpj ?? '');
            
            // Limpeza agressiva: remove qualquer parêntese vazio do final, com ou sem espaço
            $displayText = preg_replace('/\s*\(\s*\)$/', '', $displayText);
            
            if (!empty($documento) && $documento !== '()') {
                $displayText .= ' (' . $documento . ')';
            }
            
            // Fallback final: se depois de tudo ainda tiver ' ()', remove
            $displayText = str_replace(' ()', '', $displayText);
            
            $results[] = [
                'id' => $client->id,
                'text' => $displayText
            ];
        }

        return $this->response->setJSON(['results' => $results]);
    }
}
