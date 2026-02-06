<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use App\Services\AccountService;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

class MainSeeder extends Seeder
{
    public function run()
    {
        // 1. Criar Planos
        $this->call('PlanSeeder');

        // 2. Criar Contas de Teste e Usuários
        $this->createTestAccount('Habitaweb Imobiliária', 'IMOBILIARIA', 'admin@habitaweb.com', 'IMOBILIARIA');
        $this->createTestAccount('Corretor João', 'CORRETOR', 'joao@corretor.com', 'PRO');
        $this->createTestAccount('Ana Maria', 'PF', 'ana@usuario.com', 'START');
    }

    private function createTestAccount(string $nome, string $tipoConta, string $email, string $planKey)
    {
        $accountService = new AccountService();
        $planModel      = new PlanModel();
        $subModel       = new SubscriptionModel();
        $userModel      = new UserModel();

        // Verificar se conta já existe pelo email na tabela de identidades do Shield
        $db = \Config\Database::connect();
        $exists = $db->table('auth_identities')->where('secret', $email)->countAllResults();
        
        if ($exists > 0) {
            return; // Já existe, pula
        }

        // 1. Salvar Conta (Account)
        $accountResult = $accountService->trySaveAccount([
            'nome'       => $nome,
            'tipo_conta' => $tipoConta,
            'email'      => $email,
            'status'     => 'ACTIVE'
        ]);

        if (!$accountResult['success']) {
            fwrite(STDERR, "Erro ao criar conta $nome: " . json_encode($accountResult['errors']) . PHP_EOL);
            return;
        }

        $account = $accountResult['data'];

        // 2. Criar Assinatura para a conta
        $plan = $planModel->where('chave', $planKey)->first();
        if ($plan) {
            $subModel->save([
                'account_id'  => $account->id,
                'plan_id'     => $plan->id,
                'status'      => 'ATIVA',
                'data_inicio' => date('Y-m-d'),
                'data_fim'    => date('Y-m-d', strtotime('+1 year')),
            ]);
        }

        // 3. Criar Usuário (Shield) vinculado à Conta
        $user = new User([
            'username'   => explode('@', $email)[0],
            'email'      => $email,
            'password'   => '12345678',
            'active'     => 1,
        ]);
        
        // Salva usuário (Shield cria identity automaticamente)
        if (!$userModel->save($user)) {
             fwrite(STDERR, "Erro ao criar usuario $email: " . implode(', ', $userModel->errors()) . PHP_EOL);
             return;
        }

        // Pega ID inserido
        $userId = $userModel->getInsertID();

        // Força update do account_id direto no banco (pois não está no allowedFields do Shield original)
        $db = \Config\Database::connect();
        $db->table('users')->where('id', $userId)->update(['account_id' => $account->id]);

        // Adicionar ao grupo 'user'
        $userProvider = auth()->getProvider();
        $user = $userProvider->findById($userId);
        if ($user) {
            $user->addGroup('user');
        }
        
        fwrite(STDOUT, "Conta '$nome' e usuario '$email' criados com sucesso." . PHP_EOL);
    }
}
