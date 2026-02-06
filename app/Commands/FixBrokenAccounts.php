<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class FixBrokenAccounts extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'fix:accounts';
    protected $description = 'Corrige usuários com account_id nulo';

    public function run(array $params)
    {
        $db = \Config\Database::connect();
        
        CLI::write('🔍 Buscando usuários com account_id NULO...', 'yellow');
        
        $brokenUsers = $db->table('users')
            ->where('account_id', null)
            ->get()
            ->getResult();
        
        if (empty($brokenUsers)) {
            CLI::write('✅ Nenhum usuário com account_id nulo!', 'green');
            return;
        }
        
        CLI::write('⚠️  Encontrei ' . count($brokenUsers) . ' usuário(s) com problema:', 'yellow');
        CLI::newLine();
        
        foreach ($brokenUsers as $u) {
            CLI::write("   ID: {$u->id} | Username: {$u->username}", 'white');
        }
        
        CLI::newLine();
        CLI::write('Criando Accounts para usuários quebrados...', 'cyan');
        
        $accountModel = model('App\Models\AccountModel');
        $planModel = model('App\Models\PlanModel');
        $subscriptionModel = model('App\Models\SubscriptionModel');
        
        foreach ($brokenUsers as $u) {
            // Get user email from auth_identities
            $identity = $db->table('auth_identities')
                ->where('user_id', $u->id)
                ->where('type', 'email_password')
                ->get()
                ->getFirstRow();
            
            $email = $identity ? $identity->secret : "user{$u->id}@example.com";
            
            // Create new account
            $accountData = [
                'nome' => "Conta de {$u->username}",
                'tipo_conta' => 'PF',
                'documento' => '',
                'status' => 'ACTIVE',
                'email' => $email
            ];
            
            $accountModel->insert($accountData);
            $newAccountId = $accountModel->getInsertID();
            
            // Update user
            $db->table('users')->where('id', $u->id)->update(['account_id' => $newAccountId]);
            
            // Create free subscription
            $freePlan = $planModel->where('preco_mensal', 0)->first();
            
            if ($freePlan) {
                $subscriptionModel->insert([
                    'account_id' => $newAccountId,
                    'plan_id' => $freePlan->id,
                    'status' => 'ATIVA',
                    'data_inicio' => date('Y-m-d'),
                    'price' => 0.00
                ]);
            }
            
            CLI::write("   ✅ Corrigido: {$u->username} → Account #{$newAccountId}", 'green');
        }
        
        CLI::newLine();
        CLI::write('✨ Todas as contas foram corrigidas!', 'green');
    }
}
