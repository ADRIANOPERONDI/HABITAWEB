<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ResetPassword extends BaseCommand
{
    protected $group       = 'Auth';
    protected $name        = 'auth:reset-password';
    protected $description = 'Reseta a senha de um usuário';

    public function run(array $params)
    {
        $email = $params[0] ?? CLI::prompt('Email do usuário');
        $newPassword = $params[1] ?? CLI::prompt('Nova senha (mínimo 8 caracteres)');
        
        if (strlen($newPassword) < 8) {
            CLI::error('A senha deve ter no mínimo 8 caracteres!');
            return;
        }
        
        // Find user by email in auth_identities
        $db = \Config\Database::connect();
        
        $identity = $db->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->get()
            ->getFirstRow();
        
        if (!$identity) {
            CLI::error("❌ Usuário com email '{$email}' não encontrado!");
            return;
        }
        
        $userId = $identity->user_id;
        
        // Get user
        $userModel = model('App\Models\UserModel');
        $user = $userModel->find($userId);
        
        if (!$user) {
            CLI::error("❌ Usuário ID {$userId} não encontrado!");
            return;
        }
        
        // Update password
        $user->password = $newPassword;
        
        if ($userModel->save($user)) {
            CLI::write("✅ Senha alterada com sucesso para: {$user->username}", 'green');
            CLI::write("📧 Email: {$email}", 'cyan');
            CLI::write("🔑 Nova senha: {$newPassword}", 'yellow');
        } else {
            CLI::error("❌ Erro ao alterar senha!");
            print_r($userModel->errors());
        }
    }
}
