<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\UserModel;

class UsersController extends BaseController
{
    public function index()
    {
        $model = model('App\Models\UserModel');
        
        // Join com auth_identities para trazer o email (secret)
        $users = $model->select('users.*, auth_identities.secret as email')
                       ->join('auth_identities', 'auth_identities.user_id = users.id AND auth_identities.type = \'email_password\'', 'left')
                       ->orderBy('users.id', 'DESC')
                       ->paginate(20);

        $data = [
            'users' => $users,
            'pager' => $model->pager
        ];

        return view('admin/users/index', $data);
    }

    public function edit($id)
    {
        $model = model('App\Models\UserModel');
        $user = $model->select('users.*, auth_identities.secret as email')
                      ->join('auth_identities', 'auth_identities.user_id = users.id AND auth_identities.type = \'email_password\'', 'left')
                      ->find($id);

        if (!$user) {
            return redirect()->to('admin/users')->with('error', 'Usuário não encontrado.');
        }

        return view('admin/users/form', [
            'user' => $user
        ]);
    }

    public function update($id)
    {
        $model = model('App\Models\UserModel');
        $user = $model->find($id);

        if (!$user) {
            return redirect()->to('admin/users')->with('error', 'Usuário não encontrado.');
        }

        $postData = $this->request->getPost();
        
        // Dados para a tabela 'users'
        $userData = [
            'username' => $postData['username'],
            'active'   => isset($postData['active']) ? 1 : 0
        ];

        if ($model->update($id, $userData)) {
            // Atualizar e-mail na tabela 'auth_identities'
            $db = \Config\Database::connect();
            $db->table('auth_identities')
               ->where('user_id', $id)
               ->where('type', 'email_password')
               ->update(['secret' => $postData['email']]);

            return redirect()->to('admin/users')->with('message', 'Usuário atualizado com sucesso.');
        }

        return redirect()->back()->withInput()->with('errors', $model->errors());
    }

}
