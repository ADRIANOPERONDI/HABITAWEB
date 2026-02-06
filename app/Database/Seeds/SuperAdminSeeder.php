<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

class SuperAdminSeeder extends Seeder
{
    public function run()
    {
        $userModel = new UserModel();

        // 1. Verificar se usuário já existe
        $email = 'super@habitaweb.com';
        $existing = $userModel->findByCredentials(['email' => $email]);

        if ($existing) {
            return;
        }

        // 2. Criar Usuário
        $user = new User([
            'username' => 'superadmin',
            'email'    => $email,
            'password' => 'super123',
            'active'   => 1,
        ]);

        if ($userModel->save($user)) {
             $user = $userModel->findById($userModel->getInsertID());
             
             // 3. Adicionar ao grupo 'superadmin'
             $user->addGroup('superadmin');
             $user->addGroup('admin'); // Também admin normal
             
             echo "Super Admin criado: $email / super123\n";
        } else {
             echo "Erro ao criar Super Admin: " . implode(', ', $userModel->errors()) . "\n";
        }
    }
}
