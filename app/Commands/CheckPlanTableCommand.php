<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CheckPlanTableCommand extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'db:check-plans';
    protected $description = 'Check plans table directly';
    protected $usage       = 'php spark db:check-plans';

    public function run(array $params = [])
    {
        $db = \Config\Database::connect();
        $builder = $db->table('plans');

        CLI::write('Running query to check plans table...', 'yellow');
        CLI::write('', 'white');

        try {
            $result = $builder->get()->getResultArray();
            
            if (empty($result)) {
                CLI::write('❌ Nenhum plano encontrado na tabela!', 'red');
            } else {
                CLI::write(sprintf('✅ Encontrados %d planos:', count($result)), 'green');
                CLI::write('', 'white');
                
                foreach ($result as $plan) {
                    print_r($plan);
                    CLI::write('---', 'yellow');
                }
            }
        } catch (\Throwable $e) {
            CLI::write('❌ Erro ao consultar tabela: ' . $e->getMessage(), 'red');
        }
    }
}
