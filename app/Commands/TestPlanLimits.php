<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TestPlanLimits extends BaseCommand
{
    protected $group       = 'App';
    protected $name        = 'app:test-limits';
    protected $description = 'Testa os limites de cadastro de imóveis por plano.';

    public function run(array $params)
    {
        $propertyService = service('propertyService');
        $planModel = model('App\Models\PlanModel');
        $subModel = model('App\Models\SubscriptionModel');
        $propModel = model('App\Models\PropertyModel');

        CLI::write('Iniciando Teste de Limites...', 'yellow');

        // 1. Setup: Limpar dados de teste (Imóveis da conta 1)
        $accountId = 1;
        $propModel->where('account_id', $accountId)->delete();
        CLI::write('1. Imóveis da conta limpos.', 'green');

        // 2. Setup: Configurar Plano Free (Limite 3)
        // Cria plano de teste se não existir
        $freePlan = $planModel->where('nome', 'Plano Teste Free')->first();
        if (!$freePlan) {
            $id = $planModel->insert([
                'nome' => 'Plano Teste Free',
                'chave' => 'TEST_FREE',
                'preco_mensal' => 0,
                'limite_imoveis_ativos' => 3,
                'limite_fotos_por_imovel' => 5,
                'ativo' => 1
            ]);
            $freePlan = $planModel->find($id);
        }
        
        // Atualiza assinatura da conta 1
        $subModel->where('account_id', $accountId)->delete();
        $subModel->save([
            'account_id' => $accountId,
            'plan_id'    => $freePlan->id,
            'status'     => 'ATIVA',
            'data_inicio'=> date('Y-m-d'),
            'data_fim'   => date('Y-m-d', strtotime('+1 year'))
        ]);
        CLI::write("2. Plano configurado: {$freePlan->nome} (Limite: {$freePlan->limite_imoveis_ativos})", 'green');

        // 3. Tentar criar 3 imóveis (Deve passar)
        CLI::write('3. Tentando criar 3 imóveis...', 'yellow');
        for ($i = 1; $i <= 3; $i++) {
            $data = [
                'account_id' => $accountId,
                'titulo' => "Imóvel Teste #{$i}",
                'tipo_negocio' => 'VENDA',
                'tipo_imovel' => 'APARTAMENTO',
                'status' => 'ACTIVE',
                'preco' => 100000 + ($i * 1000),
                'cidade' => 'São Paulo',
                'estado' => 'SP',
                'rua' => 'Rua Teste',
                'numero' => '123',
                'bairro' => 'Centro'
            ];
            
            $result = $propertyService->trySaveProperty($data);
            if ($result['success']) {
                CLI::write("   - Imóvel #{$i} criado com sucesso.", 'green');
            } else {
                CLI::error("   - Falha ao criar Imóvel #{$i}: " . json_encode($result['errors']));
            }
        }

        // 4. Tentar criar o 4º imóvel (Deve FALHAR e retornar Limit Error)
        CLI::write('4. Tentando criar o 4º imóvel (Deve ser BLOQUEADO)...', 'yellow');
        $data = [
            'account_id' => $accountId,
            'titulo' => "Imóvel Teste #4 (Bloqueado)",
            'tipo_negocio' => 'VENDA',
            'tipo_imovel' => 'APARTAMENTO',
            'status' => 'ACTIVE',
            'preco' => 500000,
            'cidade' => 'São Paulo',
            'estado' => 'SP',
            'rua' => 'Rua Teste',
            'numero' => '999',
            'bairro' => 'Centro'
        ];
        
        $result = $propertyService->trySaveProperty($data);
        
        if (!$result['success'] && isset($result['errors']['limit'])) {
            CLI::write("   - SUCESSO: O sistema bloqueou corretamente!", 'green');
            CLI::write("     Mensagem: " . $result['errors']['limit'], 'cyan');
        } else {
            CLI::error("   - FALHA: O sistema permitiu criar o imóvel ou deu outro erro.");
            if ($result['success']) {
                // Remove o que vazou
                $propModel->delete($result['data']->id);
            }
        }

        CLI::write('Teste Finalizado.', 'white');
    }
}
