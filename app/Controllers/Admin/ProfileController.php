<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AccountModel;

class ProfileController extends BaseController
{
    public function index()
    {
        $user = auth()->user();
        $accountModel = new AccountModel();
        
        $account = $accountModel->find($user->account_id);
        
        if (!$account) {
            return redirect()->to('admin')->with('error', 'Conta não encontrada.');
        }

        return view('admin/profile/index', [
            'account' => $account,
            'user'    => $user
        ]);
    }

    public function update()
    {
        $user = auth()->user();
        $accountModel = new AccountModel();
        
        $account = $accountModel->find($user->account_id);
        if (!$account) {
            return redirect()->back()->with('error', 'Conta não encontrada.');
        }

        $data = $this->request->getPost([
            'nome', 'email', 'telefone', 'whatsapp', 'creci', 'documento'
        ]);

        $userNome = $this->request->getPost('user_nome');
        if ($userNome) {
            $userModel = model('App\Models\UserModel');
            $userModel->update($user->id, ['nome' => $userNome]);
        }

        $file = $this->request->getFile('logo');

        if ($file && $file->isValid() && !$file->hasMoved()) {
            $newName = $file->getRandomName();
            $file->move(FCPATH . 'uploads/accounts', $newName);
            $data['logo'] = 'uploads/accounts/' . $newName;
        }

        if ($accountModel->update($account->id, $data)) {
            return redirect()->back()->with('message', 'Perfil atualizado com sucesso!');
        }

        return redirect()->back()->with('error', 'Erro ao atualizar perfil.');
    }
}
