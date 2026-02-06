<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Models\PropertyModel;
use App\Services\CurationService;

class CurationCheck extends BaseCommand
{
    protected $group       = 'Portal';
    protected $name        = 'portal:curation';
    protected $description = 'Verifica qualidade, expiração e integridade dos anúncios';

    public function run(array $params)
    {
        CLI::write('Iniciando rotina de curadoria...', 'yellow');

        $propertyModel = new PropertyModel();
        $curationService = new CurationService();
        
        // 1. Verificar imóveis expirados (ex: > 60 dias sem update)
        // Isso deveria ser configurável, mas vamos chhardcodear por enquanto
        $daysToExpire = 60;
        $dateLimit = date('Y-m-d H:i:s', strtotime("-{$daysToExpire} days"));

        $expiredProperties = $propertyModel
            ->where('status', 'ACTIVE')
            ->where('updated_at <', $dateLimit)
            ->findAll();

        $countExpired = 0;
        foreach ($expiredProperties as $prop) {
            // Marca como PRECISA_REVISAR
            // Ou se já for muito velho, PAUSA.
            // Regra:
            // > 60 dias: PRECISA_REVISAR (se alertado, envia email - futuro)
            // > 90 dias: PAUSED
            
            $daysSinceUpdate = (time() - strtotime($prop->updated_at)) / (60 * 60 * 24);
            
            if ($daysSinceUpdate > 90) {
                $prop->status = 'PAUSED';
                $prop->auto_paused = true;
                $prop->auto_paused_reason = 'Expirado por inatividade (>90 dias)';
                $propertyModel->save($prop);
                
                // Notifica proprietário que o imóvel foi pausado
                $this->sendExpirationEmail($prop, 'paused');
                $this->sendWhatsAppNotification($prop, $type = 'paused');
                
                CLI::write("Imóvel #{$prop->id} pausado (90+ dias).", 'red');
            } else {
                if (!in_array('outdated', $prop->quality_warnings ?? [])) {
                    $warnings = $prop->quality_warnings ?? [];
                    $warnings[] = 'outdated';
                    $prop->quality_warnings = $warnings;
                    $prop->moderation_status = 'PENDING_REVIEW';
                    $propertyModel->save($prop);
                    
                    // Notifica proprietário que precisa revisar
                    $this->sendExpirationEmail($prop, 'needs_review');
                    $this->sendWhatsAppNotification($prop, 'outdated');
                    
                    CLI::write("Imóvel #{$prop->id} marcado como desatualizado.", 'yellow');
                }
            }
            $countExpired++;
        }

        CLI::write("Verificação de validade concluída. {$countExpired} imóveis processados.", 'green');

        // 2. Re-validar quality scores (ex: rodar para processar lógica nova)
        // Isso pode ser pesado, então rodar em chunks ou sob demanda.
        // Por hora, apenas expiração.
    }

    /**
     * Envia email de notificação sobre expiração do imóvel
     */
    private function sendExpirationEmail($property, $type)
    {
        $accountModel = model('App\Models\AccountModel');
        $userModel = model('CodeIgniter\Shield\Models\UserModel');
        
        // Busca o proprietário do imóvel
        $account = $accountModel->find($property->account_id);
        if (!$account) return;
        
        $users = $userModel->where('account_id', $account->id)->findAll();
        if (empty($users)) return;
        
        $email = \Config\Services::email();
        
        foreach ($users as $user) {
            $userEmail = $user->email ?? $user->getEmail();
            if (!$userEmail) continue;
            
            if ($type === 'paused') {
                $subject = 'Seu imóvel foi pausado por inatividade';
                $message = view('emails/property_paused', [
                    'property' => $property,
                    'account' => $account
                ]);
            } else {
                $subject = 'Seu imóvel precisa de atualização';
                $message = view('emails/property_needs_review', [
                    'property' => $property,
                    'account' => $account
                ]);
            }
            
            try {
                $email->setTo($userEmail);
                $email->setSubject($subject);
                $email->setMessage($message);
                $email->send();
                CLI::write("  Email enviado para {$userEmail}", 'green');
            } catch (\Exception $e) {
                CLI::write("  Erro ao enviar email: " . $e->getMessage(), 'red');
            }
        }
    }

    /**
     * Envia notificação por WhatsApp sobre status do imóvel
     */
    private function sendWhatsAppNotification($property, $type)
    {
        $notificationService = new \App\Services\NotificationService();
        $userModel = model('CodeIgniter\Shield\Models\UserModel');
        
        $users = $userModel->where('account_id', $property->account_id)->findAll();
        if (empty($users)) return;
        
        foreach ($users as $user) {
            $notificationService->notifyPropertyNeedsReview($property, $user, $type);
        }
    }
}
