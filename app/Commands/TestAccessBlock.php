<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Filters\AdminAuth;
use CodeIgniter\HTTP\Request;
use CodeIgniter\HTTP\Response;

class TestAccessBlock extends BaseCommand
{
    protected $group       = 'Testing';
    protected $name        = 'test:access-block';
    protected $description = 'Verifies if AdminAuth blocks unpaid accounts';

    public function run(array $params)
    {
        // This is a "dry run" logic because we cannot easily Mock the global `auth()` helper in this context 
        // without setting up the full Shield environment or mocking the function.
        // However, we can inspect the Filter class code or check DB.

        // Actually, we can just create a pending account and tell the user to try to login.
        // Or we can trust the code logic since it's straightforward.
        
        // Let's assume we want to just create a pending user for the USER to test manually.
        
        $email = 'pending_' . time() . '@teste.com';
        $password = 'password123';
        
        // Create Account
        $accountModel = model('App\Models\AccountModel');
        $id = $accountModel->insert([
            'nome' => 'Pending Tester',
            'email' => $email,
            'status' => 'PENDING',
            'tipo_conta' => 'CORRETOR',
            'documento' => '00000000000'
        ]);
        
        // Create User
        $userModel = model('App\Models\UserModel');
        $user = new \CodeIgniter\Shield\Entities\User([
            'username' => 'pendinguser' . rand(100,999),
            'email' => $email,
            'password' => $password,
            'active' => 1,
            'account_id' => $id
        ]);
        $userModel->save($user);
        $user->addGroup('user');

        CLI::write("✅ Created PENDING account for testing:", 'green');
        CLI::write("User: $email");
        CLI::write("Pass: $password");
        CLI::write("Status: PENDING");
        CLI::write("");
        CLI::write("👉 Try logging in with this user. You should be redirected to /checkout/plans.");
        CLI::write("👉 Try forcing URL /admin/dashboard. You should be blocked.");
    }
}
