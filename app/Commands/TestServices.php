<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Services\AccountService;
use App\Services\PropertyService;

class TestServices extends BaseCommand
{
    protected $group       = 'Testing';
    protected $name        = 'test:services';
    protected $description = 'Testa os services de Account e Property.';

    public function run(array $params)
    {
        CLI::write('Iniciando testes de integracao...', 'yellow');

        $accountService = new AccountService();
        $propertyService = new PropertyService();

        // 1. Criar Conta
        CLI::write('1. Testando Criacao de Conta...', 'light_blue');
        $uniq = uniqid();
        $accountData = [
            'tipo_conta' => 'IMOBILIARIA',
            'nome'       => 'Imobiliaria Teste ' . $uniq,
            'email'      => 'teste_' . $uniq . '@email.com',
            'status'     => 'ATIVO'
        ];

        $resAccount = $accountService->trySaveAccount($accountData);
        if ($resAccount['success']) {
            CLI::write('Conta criada com sucesso! ID: ' . $resAccount['data']->id, 'green');
        } else {
            CLI::error('Erro ao criar conta: ' . json_encode($resAccount['errors']));
            return;
        }

        // 2. Criar Imovel
        CLI::write('2. Testando Criacao de Imovel...', 'light_blue');
        $propertyData = [
            'account_id'      => $resAccount['data']->id,
            'tipo_negocio'    => 'VENDA',
            'tipo_imovel'     => 'APARTAMENTO',
            'titulo'          => 'Apartamento de Luxo Teste',
            'cidade'          => 'Sao Paulo',
            'bairro'          => 'Jardins',
            'status'          => 'DRAFT',
            'preco'           => 1500000.00
        ];

        $resProperty = $propertyService->trySaveProperty($propertyData);
        if ($resProperty['success']) {
            CLI::write('Imovel criado com sucesso! ID: ' . $resProperty['data']->id, 'green');
        } else {
            CLI::error('Erro ao criar imovel: ' . json_encode($resProperty['errors']));
            return;
        }

        // 3. Ativar Imovel (deve falhar se nao tiver plano)
        CLI::write('3. Testando Ativacao de Imovel (Teste de limite)...', 'light_blue');
        $resActivate = $propertyService->trySaveProperty(['status' => 'ACTIVE'], $resProperty['data']->id);
        
        if (!$resActivate['success']) {
            CLI::write('Bloqueio de limite funcionou corretamente. Msg: ' . $resActivate['message'], 'green');
        } else {
            CLI::write('Imovel ativado (Inesperado sem plano): ' . $resActivate['message'], 'red');
        }

        CLI::write('Testes concluidos.', 'yellow');
    }
}
