<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class DeepReset extends BaseCommand
{
    protected $group       = 'Maintenance';
    protected $name        = 'db:deep-reset';
    protected $description = 'Deletes all customer data, keeping only admin (User ID 1)';

    public function run(array $params)
    {
        $db = \Config\Database::connect();

        $adminUserId = 1;
        $adminUser = $db->table('users')->where('id', $adminUserId)->get()->getRow();

        if (!$adminUser) {
            CLI::error("Master user (ID $adminUserId) not found!");
            return;
        }

        $adminAccountId = $adminUser->account_id;
        CLI::write("Master User: ID $adminUserId", 'yellow');
        CLI::write("Master Account: ID $adminAccountId", 'yellow');

        $tablesToClean = [
            'subscriptions' => 'account_id',
            'payment_transactions' => 'account_id',
            'auth_groups_users' => 'user_id',
            'auth_identities' => 'user_id',
            'auth_permissions_users' => 'user_id',
            'auth_remember_tokens' => 'user_id',
            'users' => 'id',
            'accounts' => 'id'
        ];

        foreach ($tablesToClean as $table => $column) {
            $builder = $db->table($table);
            
            if ($column === 'id' || $column === 'user_id') {
                $count = $builder->where($column . ' !=', $adminUserId)->countAllResults();
                CLI::write("Cleaning $table: $count records to remove...");
                $db->table($table)->where($column . ' !=', $adminUserId)->delete();
            } else {
                $count = $builder->where($column . ' !=', $adminAccountId)->countAllResults();
                CLI::write("Cleaning $table: $count records to remove...");
                $db->table($table)->where($column . ' !=', $adminAccountId)->delete();
            }
        }

        CLI::write("Cleanup complete!", 'green');
    }
}
