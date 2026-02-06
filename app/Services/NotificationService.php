<?php

namespace App\Services;

use Config\Email;
use CodeIgniter\Config\Factories;

class NotificationService
{
    /**
     * Envia um e-mail carregando as configurações do banco de dados (system_settings)
     * 
     * @param string $to Destinatário
     * @param string $subject Assunto
     * @param string $message Mensagem (HTML ou Texto)
     * @return bool
     */
    public function sendEmail(string $to, string $subject, string $message): bool
    {
        $email = \Config\Services::email();

        // Carrega configurações dinâmicas de SMTP do banco
        $config = [];
        $config['SMTPHost']     = app_setting('mail.host', 'localhost');
        $config['SMTPUser']     = app_setting('mail.user', '');
        $config['SMTPPass']     = app_setting('mail.pass', '');
        $config['SMTPPort']     = (int)app_setting('mail.port', 587);
        $config['SMTPCrypto']   = app_setting('mail.crypto', 'tls');
        $config['mailType']     = 'html';
        $config['charset']      = 'utf-8';
        $config['protocol']     = 'smtp';
        $config['newline']      = "\r\n";
        
        $email->initialize($config);

        // Verifica se o SMTP parece configurado (evita tentar conectar em smtp.example.com ou localhost sem servidor rodando)
        if (in_array($config['SMTPHost'], ['localhost', 'smtp.example.com', '']) || empty($config['SMTPUser'])) {
            log_message('warning', 'SMTP não configurado. Pulei o envio de e-mail para ' . $to);
            return false;
        }

        $fromEmail = app_setting('mail.from_email', 'noreply@portal.com');
        $fromName  = app_setting('mail.from_name', 'Habitaweb');

        $email->setFrom($fromEmail, $fromName);
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);

        // Usa try-catch para evitar que fsockopen() quebre a execução se o host for inválido
        try {
            if ($email->send(false)) { // false para não lançar exceção automática se possível
                return true;
            }
        } catch (\Exception $e) {
            log_message('error', 'Exceção ao enviar e-mail para ' . $to . ': ' . $e->getMessage());
        }

        log_message('error', 'Falha no envio de e-mail para ' . $to . ': ' . $email->printDebugger(['headers']));
        return false;
    }

    /**
     * Envia notificação via WhatsApp para um número específico
     * 
     * @param string $number Número com DDD (ex: 11999999999)
     * @param string $message Texto da mensagem
     * @return bool
     */
    public function sendWhatsApp(string $number, string $message): bool
    {
        // Verifica se a notificação via WhatsApp está ativa globalmente
        if (app_setting('notify.whatsapp_leads', '0') !== '1') {
            return false;
        }

        // Utiliza o helper de API existente (assumindo que api_helper.php está carregado ou disponível)
        if (!function_exists('enviar_whatsapp')) {
            helper('api'); 
        }

        if (function_exists('enviar_whatsapp')) {
             return enviar_whatsapp($number, $message);
        }

        log_message('warning', 'Tentativa de envio de WhatsApp sem api_helper ou função enviar_whatsapp disponível.');
        return false;
    }

    /**
     * Salva notificação no banco de dados
     */
    private function saveNotification(int $userId, string $title, string $message, string $type = 'info', ?string $link = null, ?int $accountId = null)
    {
        try {
            $model = model('App\Models\NotificationModel');
            $model->insert([
                'user_id'    => $userId,
                'account_id' => $accountId,
                'title'      => $title,
                'message'    => $message,
                'type'       => $type,
                'link'       => $link,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Erro ao salvar notificação: ' . $e->getMessage());
        }
    }

    /**
     * Notifica um anunciante sobre um novo Lead
     */
    public function notifyNewLead(array $leadData, array $anuncianteData)
    {
        if (app_setting('notify.leads', '1') !== '1') {
            return;
        }

        $subject = "Novo Lead Recebido: " . $leadData['nome'];
        
        $emailBody = "
            <h2>Você recebeu um novo contato!</h2>
            <p><strong>Nome:</strong> {$leadData['nome']}</p>
            <p><strong>E-mail:</strong> {$leadData['email']}</p>
            <p><strong>Telefone:</strong> {$leadData['telefone']}</p>
            <p><strong>Mensagem:</strong><br>{$leadData['mensagem']}</p>
            <hr>
            <p>Acesse seu painel administrativo para responder e gerenciar este lead.</p>
        ";

        // Envia E-mail
        if (!empty($anuncianteData['email'])) {
            $this->sendEmail($anuncianteData['email'], $subject, $emailBody);
        }

        // PERSISTE NO BANCO (se tiver user_id no anuncianteData, assumindo que sim pois vem de Account/User)
        // Precisamos do user_id do anunciante. Se anuncianteData tiver, ótimo. Senão, tentamos buscar.
        // Geralmente notifyNewLead recebe dados crus. Vamos tentar achar o usuário dono da conta.
        if (!empty($anuncianteData['id'])) { // ID da conta
             $userModel = model('CodeIgniter\Shield\Models\UserModel');
             // Notifica todos os usuários da conta? Ou o principal? Vamos notificar todos.
             $users = $userModel->where('account_id', $anuncianteData['id'])->findAll();
             foreach ($users as $u) {
                 $this->saveNotification(
                     $u->id,
                     'Novo Lead Recebido',
                     "{$leadData['nome']} enviou uma mensagem.",
                     'success',
                     site_url('admin/leads'),
                     $anuncianteData['id']
                 );
             }
        }

        // Envia WhatsApp (se o anunciante tiver número cadastrado e a flag estiver ativa)
        if (!empty($anuncianteData['telefone'])) {
            $waMessage = "*Novo Lead no Portal!* \n\n*Nome:* {$leadData['nome']}\n*Fone:* {$leadData['telefone']}\n*Mensagem:* {$leadData['mensagem']}";
            $this->sendWhatsApp($anuncianteData['telefone'], $waMessage);
        }
    }

    /**
     * Notifica usuário sobre vencimento próximo da assinatura
     * 
     * @param object $user Usuário (Shield)
     * @param object $subscription Entidade Subscription
     * @param int $daysRemaining Dias restantes até vencimento
     * @return bool
     */
    public function notifySubscriptionExpiring($user, $subscription, int $daysRemaining): bool
    {
        $userEmail = $user->email ?? $user->getEmail();
        if (empty($userEmail)) return false;

        $subject = "⚠️ Sua assinatura vence em {$daysRemaining} " . ($daysRemaining === 1 ? 'dia' : 'dias');
        
        $message = view('emails/subscription_expiring', [
            'user' => $user,
            'subscription' => $subscription,
            'daysRemaining' => $daysRemaining
        ]);

        // Persiste
        $this->saveNotification(
            $user->id,
            'Assinatura Vencendo',
            "Sua assinatura vence em {$daysRemaining} dias. Renove agora para evitar bloqueio.",
            'warning',
            site_url('admin/subscription'),
            $user->account_id
        );

        $sent = $this->sendEmail($userEmail, $subject, $message);

        // WhatsApp (se número disponível)
        $account = model('App\Models\AccountModel')->find($user->account_id);
        if ($account && !empty($account->telefone)) {
            $waMessage = "⚠️ *Atenção!* Sua assinatura vence em *{$daysRemaining} " . 
                         ($daysRemaining === 1 ? 'dia' : 'dias') . 
                         "*. Acesse o painel para renovar e evitar interrupções.";
            $this->sendWhatsApp($account->telefone, $waMessage);
        }

        return $sent;
    }

    /**
     * Notifica usuário que está próximo do limite de imóveis
     * 
     * @param object $user Usuário (Shield)
     * @param object $account Conta (Account)
     * @param int $currentCount Quantidade atual de imóveis ativos
     * @param int $limitCount Limite do plano
     * @param int $percentage Percentual atingido (90, 95, 100)
     * @return bool
     */
    public function notifyPropertyLimitApproaching($user, $account, int $currentCount, int $limitCount, int $percentage): bool
    {
        $userEmail = $user->email ?? $user->getEmail();
        if (empty($userEmail)) return false;

        $subject = $percentage >= 100 
            ? "🚫 Limite de imóveis atingido!" 
            : "⚠️ Você está usando {$percentage}% do seu limite de imóveis";
        
        $message = view('emails/property_limit_warning', [
            'user' => $user,
            'account' => $account,
            'currentCount' => $currentCount,
            'limitCount' => $limitCount,
            'percentage' => $percentage
        ]);

        // Persiste
        $type = $percentage >= 100 ? 'error' : 'warning';
        $title = $percentage >= 100 ? 'Limite Atingido' : 'Limite Próximo';
        $msg = $percentage >= 100 
            ? "Você atingiu o limite de {$limitCount} imóveis." 
            : "Você usou {$percentage}% do limite ({$currentCount}/{$limitCount}).";

        $this->saveNotification(
            $user->id,
            $title,
            $msg,
            $type,
            site_url('admin/subscription'),
            $account->id
        );

        $sent = $this->sendEmail($userEmail, $subject, $message);

        // WhatsApp
        if (!empty($account->telefone)) {
            $waMessage = $percentage >= 100 
                ? "🚫 *Limite Atingido!* Você já possui {$currentCount} imóveis ativos (limite: {$limitCount}). Faça upgrade para continuar publicando."
                : "⚠️ Você está usando *{$percentage}%* do seu limite de imóveis ({$currentCount}/{$limitCount}). Considere fazer upgrade do plano!";
            $this->sendWhatsApp($account->telefone, $waMessage);
        }

        return $sent;
    }

    /**
     * Notifica usuário que um imóvel precisa de revisão/atualização
     * 
     * @param object $property Entidade Property
     * @param object $user Usuário (Shield)
     * @param string $reason Motivo (outdated, paused)
     * @return bool
     */
    public function notifyPropertyNeedsReview($property, $user, string $reason = 'outdated'): bool
    {
        $type = $reason === 'paused' ? 'error' : 'warning';
        $title = $reason === 'paused' ? 'Imóvel Pausado' : 'Atualize seu Imóvel';
        $msg = $reason === 'paused' 
            ? "Imóvel #{$property->id} pausado por inatividade." 
            : "Imóvel #{$property->id} precisa de atualização.";

        // Persiste
        $this->saveNotification(
            $user->id,
            $title,
            $msg,
            $type,
            site_url("admin/properties/{$property->id}/edit"),
            $user->account_id
        );

        $userEmail = $user->email ?? $user->getEmail();
        if (empty($userEmail)) return false;

        // Email já é enviado pelo CurationCheck
        // Aqui só adicionamos WhatsApp

        $account = model('App\Models\AccountModel')->find($user->account_id);
        if (!$account || empty($account->telefone)) return false;

        if ($reason === 'paused') {
            $waMessage = "⛔ *Imóvel Pausado!* O imóvel #{$property->id} foi pausado por inatividade (90+ dias). Acesse o painel para atualizar e reativar.";
        } else {
            $waMessage = "⚠️ *Atualize seu Imóvel!* O imóvel #{$property->id} está há mais de 60 dias sem atualização. Atualize para melhorar o ranking!";
        }

        return $this->sendWhatsApp($account->telefone, $waMessage);
    }

    /**
     * Envia e-mail com lista de novos imóveis compatíveis com o alerta do usuário
     * 
     * @param array $alert Dados do alerta do usuário
     * @param array $properties Lista de imóveis (Entities)
     * @return bool
     */
    public function sendPropertyAlertEmail(array $alert, array $properties): bool
    {
        $subject = "🏠 " . count($properties) . " novos imóveis encontrados para sua busca!";
        
        $message = view('emails/property_alert', [
            'alert'      => $alert,
            'properties' => $properties
        ]);

        return $this->sendEmail($alert['email'], $subject, $message);
    }
}
