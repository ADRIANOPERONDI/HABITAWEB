<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSmtpSettings extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        
        $data = [
            // SMTP SETTINGS
            [
                'key' => 'mail.host', 
                'value' => 'smtp.example.com', 
                'group' => 'email', 
                'type' => 'string', 
                'label' => 'Servidor SMTP (Host)', 
                'description' => 'Endereço do servidor de e-mail (ex: smtp.gmail.com).',
                'created_at' => $now
            ],
            [
                'key' => 'mail.user', 
                'value' => 'user@example.com', 
                'group' => 'email', 
                'type' => 'string', 
                'label' => 'Usuário SMTP', 
                'description' => 'Geralmente o seu endereço de e-mail completo.',
                'created_at' => $now
            ],
            [
                'key' => 'mail.pass', 
                'value' => '', 
                'group' => 'email', 
                'type' => 'string', 
                'label' => 'Senha SMTP', 
                'description' => 'Senha da sua conta de e-mail ou senha de aplicativo.',
                'created_at' => $now
            ],
            [
                'key' => 'mail.port', 
                'value' => '587', 
                'group' => 'email', 
                'type' => 'string', 
                'label' => 'Porta SMTP', 
                'description' => 'Geralmente 587 (TLS/STARTTLS) ou 465 (SSL).',
                'created_at' => $now
            ],
            [
                'key' => 'mail.crypto', 
                'value' => 'tls', 
                'group' => 'email', 
                'type' => 'string', 
                'label' => 'Protocolo de Segurança', 
                'description' => 'Defina como "tls" ou "ssl". Deixe em branco se não houver.',
                'created_at' => $now
            ],
            [
                'key' => 'mail.from_email', 
                'value' => 'noreply@habitaweb.com.br', 
                'group' => 'email', 
                'type' => 'string', 
                'label' => 'E-mail do Remetente', 
                'description' => 'O e-mail que aparecerá como quem enviou a mensagem.',
                'created_at' => $now
            ],
            [
                'key' => 'mail.from_name', 
                'value' => 'Habitaweb', 
                'group' => 'email', 
                'type' => 'string', 
                'label' => 'Nome do Remetente', 
                'description' => 'O nome que aparecerá no campo "De:" do e-mail.',
                'created_at' => $now
            ],

            // NOTIFICATION FLAGS
            [
                'key' => 'notify.leads', 
                'value' => '1', 
                'group' => 'notifications', 
                'type' => 'boolean', 
                'label' => 'Notificar Novos Leads', 
                'description' => 'Enviar alertas ao anunciante quando receber novos contatos.',
                'created_at' => $now
            ],
            [
                'key' => 'notify.whatsapp_leads', 
                'value' => '0', 
                'group' => 'notifications', 
                'type' => 'boolean', 
                'label' => 'Notificar via WhatsApp', 
                'description' => 'Tentar enviar lead também via WhatsApp (requer API ativa).',
                'created_at' => $now
            ],
            [
                'key' => 'notify.subscription_expiry', 
                'value' => '1', 
                'group' => 'notifications', 
                'type' => 'boolean', 
                'label' => 'Alertas de Vencimento', 
                'description' => 'Avisar anunciantes quando suas assinaturas estiverem vencendo.',
                'created_at' => $now
            ],
            [
                'key' => 'notify.low_limits', 
                'value' => '1', 
                'group' => 'notifications', 
                'type' => 'boolean', 
                'label' => 'Alertas de Limite de Imóveis', 
                'description' => 'Avisar quando o limite de anúncios ativos estiver próximo do fim.',
                'created_at' => $now
            ],
        ];
        
        foreach ($data as $row) {
            // Verifica se a configuração já existe para evitar erro de unicidade
            $exists = $db->table('system_settings')
                        ->where('key', $row['key'])
                        ->countAllResults();
            
            if ($exists === 0) {
                $db->table('system_settings')->insert($row);
            }
        }
    }

    public function down()
    {
        $db = \Config\Database::connect();
        $db->table('system_settings')->whereIn('group', ['email', 'notifications'])->delete();
    }
}
