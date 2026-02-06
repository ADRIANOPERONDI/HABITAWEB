<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\SubscriptionModel;
use App\Services\NotificationService;

class SubscriptionCheck extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'subscription:check';
    protected $description = 'Verifica assinaturas próximas do vencimento e envia notificações';

    public function run(array $params)
    {
        CLI::write('Iniciando verificação de assinaturas...', 'yellow');

        $subscriptionModel = model(SubscriptionModel::class);
        $notificationService = new NotificationService();
        $userModel = model('CodeIgniter\Shield\Models\UserModel');
        
        // Arrays de dias para alertar (7, 3 e 1 dia antes)
        $daysToCheck = [7, 3, 1];
        $totalNotified = 0;
        
        foreach ($daysToCheck as $days) {
            // Calcula data alvo (hoje + $days dias)
            $targetDate = date('Y-m-d', strtotime("+{$days} days"));
            
            // Busca assinaturas que vencem nesta data
            $expiringSubscriptions = $subscriptionModel
                ->where('status', 'ACTIVE')
                ->where('DATE(data_final)', $targetDate)
                ->findAll();
            
            CLI::write("Verificando assinaturas que vencem em {$days} " . ($days === 1 ? 'dia' : 'dias') . "...", 'cyan');
            
            foreach ($expiringSubscriptions as $subscription) {
                // Busca usuário da conta
                $users = $userModel->where('account_id', $subscription->account_id)->findAll();
                
                if (empty($users)) {
                    CLI::write("  Assinatura #{$subscription->id}: Sem usuários vinculados", 'red');
                    continue;
                }
                
                // Notifica cada usuário da conta
                foreach ($users as $user) {
                    $sent = $notificationService->notifySubscriptionExpiring(
                        $user,
                        $subscription,
                        $days
                    );
                    
                    if ($sent) {
                        $userEmail = $user->email ?? $user->getEmail();
                        CLI::write("  ✅ Notificação enviada para {$userEmail} (vence em {$days} dias)", 'green');
                        $totalNotified++;
                    } else {
                        CLI::write("  ❌ Falha ao notificar usuário ID {$user->id}", 'red');
                    }
                }
            }
            
            if (empty($expiringSubscriptions)) {
                CLI::write("  Nenhuma assinatura vence em {$days} dias", 'yellow');
            }
        }

        CLI::write("\nVerificação concluída. Total de notificações enviadas: {$totalNotified}", 'green');
        
        // Opcional: Verificar assinaturas já vencidas (para limpar automaticamente)
        $this->checkExpiredSubscriptions();
    }

    /**
     * Verifica e marca como expiradas as assinaturas vencidas
     */
    private function checkExpiredSubscriptions()
    {
        CLI::write("\nVerificando assinaturas vencidas...", 'yellow');
        
        $subscriptionModel = model(SubscriptionModel::class);
        
        $expired = $subscriptionModel
            ->where('status', 'ACTIVE')
            ->where('data_final <', date('Y-m-d H:i:s'))
            ->findAll();
        
        $count = 0;
        foreach ($expired as $subscription) {
            $subscription->status = 'EXPIRED';
            $subscriptionModel->save($subscription);
            CLI::write("  Assinatura #{$subscription->id} marcada como EXPIRADA", 'red');
            $count++;
        }
        
        if ($count > 0) {
            CLI::write("\n{$count} assinatura(s) marcada(s) como expirada(s).", 'green');
        } else {
            CLI::write("Nenhuma assinatura vencida encontrada.", 'green');
        }
    }
}
