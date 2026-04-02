<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TestInsertPromotionCommand extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'db:test-insert-promotion';
    protected $description = 'Test inserting promotion packages';
    protected $usage       = 'php spark db:test-insert-promotion';

    public function run(array $params = [])
    {
        $db = \Config\Database::connect();
        $builder = $db->table('promotion_packages');

        CLI::write('Testing promotion package inserts...', 'yellow');
        CLI::write('', 'white');

        try {
            // Test 1: LEAD_COMPRA
            CLI::write('Test 1: Inserting LEAD_COMPRA...', 'cyan');
            $data1 = [
                'chave'         => 'LEAD_COMPRA',
                'nome'          => 'Lead - Compra',
                'tipo_promocao' => 'LEAD',
                'duracao_dias'  => null,
                'preco'         => 80.00,
                'created_at'    => date('Y-m-d H:i:s'),
            ];
            
            $result1 = $builder->insert($data1);
            if ($result1) {
                CLI::write('✅ LEAD_COMPRA inserted!', 'green');
            } else {
                CLI::write('❌ LEAD_COMPRA insert failed: ' . json_encode($db->error()), 'red');
            }

            // Test 2: LEAD_ALUGUEL
            CLI::write('', 'white');
            CLI::write('Test 2: Inserting LEAD_ALUGUEL...', 'cyan');
            $data2 = [
                'chave'         => 'LEAD_ALUGUEL',
                'nome'          => 'Lead - Aluguel',
                'tipo_promocao' => 'LEAD',
                'duracao_dias'  => null,
                'preco'         => 40.00,
                'created_at'    => date('Y-m-d H:i:s'),
            ];
            
            $result2 = $builder->insert($data2);
            if ($result2) {
                CLI::write('✅ LEAD_ALUGUEL inserted!', 'green');
            } else {
                CLI::write('❌ LEAD_ALUGUEL insert failed: ' . json_encode($db->error()), 'red');
            }

            // Check all records
            CLI::write('', 'white');
            CLI::write('Checking all records...', 'cyan');
            $check = $db->table('promotion_packages')->get()->getResult();
            CLI::write(sprintf('Total records: %d', count($check)), 'yellow');
            
        } catch (\Throwable $e) {
            CLI::write('❌ Error: ' . $e->getMessage(), 'red');
        }
    }
}
