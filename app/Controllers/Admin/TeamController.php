<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

class TeamController extends BaseController
{
    protected $userModel;

    public function __construct()
    {
        $this->userModel = new UserModel();
    }

    public function index()
    {
        if (! auth()->user()->can('imobiliaria.manage_team')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $accountId = auth()->user()->account_id;

        // Lista usuários da MESMA conta, exceto o próprio usuário logado (opcional)
        // Ou lista todos.
        $team = $this->userModel
            ->where('account_id', $accountId)
            ->where('id !=', auth()->id()) // Não lista a si mesmo para evitar auto-delete
            ->findAll();

        // Para cada usuário, carregamos os grupos para exibir.
        // Shield UserModel retorna Entity, mas groups ficam em outra tabela.
        // O Entity User tem método ->getGroups() se carregado corretamento.
        
        return view('admin/team/index', [
            'team' => $team
        ]);
    }

    public function new()
    {
        if (! auth()->user()->can('imobiliaria.manage_team')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        return view('admin/team/form', ['member' => null]);
    }

    public function create()
    {
        if (! auth()->user()->can('imobiliaria.manage_team')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $rules = [
            'username' => 'required|min_length[3]|max_length[30]|is_unique[users.username]',
            'email'    => 'required|valid_email|is_unique[auth_identities.secret]', // Shield checks auth_identities
            'password' => 'required|min_length[8]',
            'role'     => 'required|in_list[imobiliaria_admin,imobiliaria_corretor]'
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $data = $this->request->getPost();
        
        // Criar usuário
        $user = new User([
            'username' => $data['username'],
            'nome'     => $data['nome'] ?? null,
            'email'    => $data['email'],
            'password' => $data['password'],
            'account_id' => auth()->user()->account_id, // Vincula à conta atual
            'active'   => 1
        ]);

        $this->userModel->save($user);

        // O ID é gerado após save. Mas Shield requer processamento de identity separado as vezes
        // Usando UserModel save com Entity User padrão do Shield deve lidar com identities basicas se configurado.
        // Mas geralmente deve-se usar $userModel->create() ou similar se houver.
        // Shield User Provider usa save().
        
        // Pega o usuário recém criado para atribuir grupo
        $newUser = $this->userModel->findById($this->userModel->getInsertID());
        
        if ($newUser) {
            $newUser->addGroup($data['role']);
            // Opcional: Ativar email se necessário, mas aqui setamos active=1
        }

        return redirect()->to(site_url('admin/team'))->with('message', 'Membro adicionado com sucesso!');
    }

    public function edit($id)
    {
        if (! auth()->user()->can('imobiliaria.manage_team')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $accountId = auth()->user()->account_id;
        $user = $this->userModel->find($id);

        if (!$user || $user->account_id != $accountId) {
             return redirect()->to(site_url('admin/team'))->with('error', 'Usuário não encontrado ou de outra conta.');
        }

        return view('admin/team/form', ['member' => $user]);
    }

    public function update($id)
    {
        if (! auth()->user()->can('imobiliaria.manage_team')) {
            return redirect()->back()->with('error', 'Acesso negado.');
        }

        $accountId = auth()->user()->account_id;
        $user = $this->userModel->find($id);

        if (!$user || $user->account_id != $accountId) {
             return redirect()->to(site_url('admin/team'))->with('error', 'Usuário inválido.');
        }

        // Validação (email unico exceto este user...)
        // Shield validation rules are tricky for updates.
        // Simplificação: só update password e role.

        $data = $this->request->getPost();

        // Update Name
        if (isset($data['nome'])) {
            $user->nome = $data['nome'];
        }

        // Update Role
        if (isset($data['role'])) {
            $user->syncGroups([$data['role']]);
        }

        // Save User (Name/Role/etc)
        $this->userModel->save($user);

        // Update Password if provided
        if (!empty($data['password'])) {
            $user->password = $data['password'];
            $this->userModel->save($user);
        }

        return redirect()->to(site_url('admin/team'))->with('message', 'Membro atualizado.');
    }

    public function delete($id)
    {
        if (! auth()->user()->can('imobiliaria.manage_team')) {
            return $this->response->setJSON(['success' => false, 'message' => 'Acesso negado.']);
        }
        
        $accountId = auth()->user()->account_id;
        $user = $this->userModel->find($id);

        if ($user && $user->account_id == $accountId) {
             // Impede deletar a si mesmo (segurança extra)
             if ($user->id == auth()->id()) {
                return $this->response->setJSON(['success' => false, 'message' => 'Você não pode remover a si mesmo.']);
             }

            $this->userModel->delete($id, true); 
            return $this->response->setJSON(['success' => true, 'message' => 'Membro removido com sucesso.']);
        }

        return $this->response->setJSON(['success' => false, 'message' => 'Membro não encontrado ou erro ao remover.']);
    }
}
