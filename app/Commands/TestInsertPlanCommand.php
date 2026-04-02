<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TestInsertPlanCommand extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'db:test-insert-plan';
    protected $description = 'Test inserting a plan directly';
    protected $usage       = 'php spark db:test-insert-plan';

    public function run(array $params = [])
    {
        $db = \Config\Database::connect();
        $builder = $db->table('plans');

        CLI::write('Testing direct insert...', 'yellow');
        CLI::write('', 'white');

        try {
            $data = [
                'chave'                   => 'TEST_PLAN_' . uniqid(),
                'nome'                    => 'Plano Teste',
                'limite_imoveis_ativos'   => 50,
                'limite_turbo_mensal'     => 10,
                'limite_api_requests_dia' => 1000,
                'preco_mensal'            => 100.00,
                'preco_anual'             => 900.00,
                'ativo'                   => 1,
                'descricao'               => 'Plano teste',
                'created_at'              => date('Y-m-d H:i:s'),
                'updated_at'              => date('Y-m-d H:i:s'),
            ];

            CLI::write('Attempting insert with data:', 'yellow');
            CLI::write(json_encode($data, JSON_PRETTY_PRINT + JSON_UNESCAPED_UNICODE), 'white');
            CLI::write('', 'white');

            $result = $builder->insert($data);
            
            if ($result) {
                CLI::write('✅ Insert bem-sucedido!', 'green');
                CLI::write('ID inserido: ' . $db->insertID(), 'white');
            } else {
                CLI::write('❌ Insert retornou false!', 'red');
                $err = $db->error();
                CLI::write('Erro do banco: ' . json_encode($err), 'red');
            }
            
            // Verificar total de registros
            $check = $db->table('plans')->get()->getResult();
            CLI::write(sprintf('Total de registros na tabela: %d', count($check)), 'white');
            
        } catch (\Throwable $e) {
            CLI::write('❌ Erro: ' . $e->getMessage(), 'red');
            CLI::write('Arquivo: ' . $e->getFile() . ':' . $e->getLine(), 'red');
        }
    }
}
