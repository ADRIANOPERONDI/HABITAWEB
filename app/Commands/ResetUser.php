<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ResetUser extends BaseCommand
{
    protected $group       = 'Maintenance';
    protected $name        = 'reset:user';
    protected $description = 'Resets subscriptions and account status for a user by email';

    public function run(array $params)
    {
        $email = array_shift($params);

        if (empty($email)) {
             $email = CLI::prompt('Email do usuário');
        }

        $db = \Config\Database::connect();
        
        // 1. Find User by email in auth_identities
        $identity = $db->table('auth_identities')
                     ->where('secret', $email)
                     ->where('type', 'email_password')
                     ->get()
                     ->getRow();

        if (!$identity) {
            CLI::error("Usuário não encontrado em auth_identities: {$email}");
            return;
        }

        $user = $db->table('users')->where('id', $identity->user_id)->get()->getRow();
        
        if (!$user) {
            CLI::error("Registro do usuário não encontrado na tabela 'users' para o ID: {$identity->user_id}");
            return;
        }
        
        $accountId = $user->account_id;
        CLI::write("Usuário encontrado: ID {$user->id}, Account ID {$accountId}", 'yellow');

        if (!$accountId) {
             CLI::error("Usuário não tem conta associada.");
             return;
        }

        // 2. Delete Subscriptions
        $db->table('subscriptions')->where('account_id', $accountId)->delete();
        $affectedSubs = $db->affectedRows();
        CLI::write("✅ {$affectedSubs} assinaturas removidas.", 'green');
        
        // 3. Delete Transactions (Optional, ensures clean history for test)
        $db->table('payment_transactions')->where('account_id', $accountId)->delete();
        $affectedTrans = $db->affectedRows();
        CLI::write("✅ {$affectedTrans} transações removidas.", 'green');

        // 4. Update Account Status
        $db->table('accounts')->where('id', $accountId)->update(['status' => 'PENDING']);
        CLI::write("✅ Status da conta atualizado para PENDING.", 'green');
        
        CLI::write("Conta pronta para novo teste de pagamento!", 'white', 'green');
    }
}
