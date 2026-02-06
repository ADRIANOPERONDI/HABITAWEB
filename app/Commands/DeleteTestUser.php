<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\AccountModel;
use CodeIgniter\Shield\Models\UserIdentityModel;

class DeleteTestUser extends BaseCommand
{
    /**
     * The Command's Group
     *
     * @var string
     */
    protected $group = 'App';

    /**
     * The Command's Name
     *
     * @var string
     */
    protected $name = 'user:delete_test';

    /**
     * The Command's Description
     *
     * @var string
     */
    protected $description = 'Deletes the test user (cristiandasilva8@gmail.com) and all related data.';

    /**
     * The Command's Usage
     *
     * @var string
     */
    protected $usage = 'user:delete_test';

    /**
     * The Command's Arguments
     *
     * @var array
     */
    protected $arguments = [];

    /**
     * The Command's Options
     *
     * @var array
     */
    protected $options = [];

    /**
     * Actually execute a command.
     *
     * @param array $params
     */
    public function run(array $params)
    {
        $email = 'cristiandasilva8@gmail.com';

        CLI::write("Iniciando remoção segura do usuário: {$email}", 'yellow');
        
        $db = \Config\Database::connect();
        
        // 1. Encontrar Account
        $accountModel = new AccountModel();
        $account = $accountModel->where('email', $email)->first();

        if ($account) {
            CLI::write("[FOUND] Conta encontrada: ID {$account->id} - {$account->nome}", 'green');
            
            // Deletar dependências
            $db->table('subscriptions')->where('account_id', $account->id)->delete();
            CLI::write("  - Assinaturas removidas.");
            
            $db->table('payment_transactions')->where('account_id', $account->id)->delete();
            CLI::write("  - Transações removidas.");
            
            $db->table('coupon_usages')->where('account_id', $account->id)->delete();
            CLI::write("  - Usos de cupom removidos.");
            
            $accountModel->delete($account->id);
            CLI::write("[OK] Conta removida.", 'green');
        } else {
            CLI::write("[INFO] Nenhuma conta encontrada para este email.", 'white');
        }

        // 2. Encontrar User no Shield
        $identityModel = new UserIdentityModel();
        $identity = $identityModel->where('secret', $email)->first();

        if ($identity) {
            $userId = $identity->user_id;
            CLI::write("[FOUND] Identidade Shield encontrada. User ID: {$userId}", 'green');

            $db->table('auth_identities')->where('user_id', $userId)->delete();
            $db->table('auth_groups_users')->where('user_id', $userId)->delete();
            
            // Delete user record
            $db->table('users')->where('id', $userId)->delete();
            
            CLI::write("[OK] Usuário de autenticação removido.", 'green');
        } else {
            CLI::write("[INFO] Nenhum usuário de auth encontrado.", 'white');
        }

        CLI::write("Limpeza concluída! 🚀", 'green');
    }
}
