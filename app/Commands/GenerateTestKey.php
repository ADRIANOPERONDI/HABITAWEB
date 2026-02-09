<?php
namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\ApiKeyModel;
use App\Models\AccountModel;

class GenerateTestKey extends BaseCommand
{
    protected $group       = 'Testing';
    protected $name        = 'test:generate_key';
    protected $description = 'Generates a test API Key';

    public function run(array $params)
    {
        $apiKeyModel = new ApiKeyModel();
        // Ensure Account 1 exists
        $account = model(AccountModel::class)->find(1);
        
        $accountId = 1;

        if (!$account) {
            model(AccountModel::class)->insert([
                'tipo_conta' => 'IMOBILIARIA',
                'nome' => 'Test Account',
                'status' => 'ACTIVE'
            ]);
            $accountId = model(AccountModel::class)->getInsertID();
            CLI::write("Created Test Account ID: $accountId");
        }

        // Use the model's method to generate the key correctly (hashing, prefix, etc)
        $result = $apiKeyModel->generateKey(
            $accountId, 
            'Automated Test Key', 
            1
        );

        if ($result['success']) {
            CLI::write('CREATED_KEY:' . $result['plain_key']);
            CLI::write('ACCOUNT_ID:' . $accountId);
        } else {
            CLI::error('Failed to generate key: ' . $result['message']);
        }
    }
}

