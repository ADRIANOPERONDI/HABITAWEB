<?php
namespace App\Commands;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ListGateways extends BaseCommand
{
    protected $group       = 'Dev';
    protected $name        = 'db:list-gateways';
    protected $description = 'Lists payment gateways in DB';

    public function run(array $params)
    {
        $db = \Config\Database::connect();
        $query = $db->table('payment_gateways')->get();
        
        foreach ($query->getResult() as $row) {
            CLI::write("{$row->id}: {$row->code} - {$row->name} (Active: {$row->is_active})");
        }
    }
}
