<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\Config\Factories;

class SettingsController extends BaseController
{
    public function index()
    {
        $model = model('App\Models\SettingModel');
        $user = auth()->user();
        $isSuperAdmin = $user->inGroup('superadmin');

        // Garante que as configurações básicas existam (Fallback se a migração falhar)
        $this->ensureSettingsExist();

        $query = $model->orderBy('group', 'ASC');
        
        // Se não for superadmin, remove grupos sensíveis
        if (!$isSuperAdmin) {
            $query->whereNotIn('group', ['email', 'notifications']);
        }

        $settings = $query->findAll();

        // Agrupa pro view
        $grouped = [];
        foreach ($settings as $s) {
            $grouped[$s->group][] = $s;
        }

        return view('admin/settings/index', [
            'grouped' => $grouped,
            'isSuperAdmin' => $isSuperAdmin
        ]);
    }

    public function testEmail()
    {
        $user = auth()->user();
        if (!$user->inGroup('superadmin')) {
            return $this->response->setJSON(['success' => false, 'message' => 'Acesso negado.']);
        }

        $service = new \App\Services\NotificationService();
        $to = $this->request->getPost('email') ?? $user->email;
        
        $success = $service->sendEmail(
            $to, 
            "Teste de Configuração SMTP - " . app_setting('site.name'),
            "<h3>Sucesso!</h3><p>Se você está lendo isso, sua configuração de SMTP no portal está funcionando corretamente.</p>"
        );

        if ($success) {
            return $this->response->setJSON(['success' => true, 'message' => "E-mail de teste enviado com sucesso para $to."]);
        }

        return $this->response->setJSON(['success' => false, 'message' => 'Falha ao enviar e-mail. Verifique os logs do sistema.']);
    }

    /**
     * Garante que as configurações de e-mail e notificações existam no banco.
     */
    protected function ensureSettingsExist()
    {
        $model = model('App\Models\SettingModel');
        $now = date('Y-m-d H:i:s');
        
        $essential = [
            'mail.host' => ['value' => 'smtp.example.com', 'group' => 'email', 'label' => 'Servidor SMTP (Host)', 'type' => 'string'],
            'mail.user' => ['value' => 'user@example.com', 'group' => 'email', 'label' => 'Usuário SMTP', 'type' => 'string'],
            'mail.pass' => ['value' => '', 'group' => 'email', 'label' => 'Senha SMTP', 'type' => 'string'],
            'mail.port' => ['value' => '587', 'group' => 'email', 'label' => 'Porta SMTP', 'type' => 'string'],
            'mail.crypto' => ['value' => 'tls', 'group' => 'email', 'label' => 'Protocolo de Segurança', 'type' => 'string'],
            'mail.from_email' => ['value' => 'noreply@portal.com', 'group' => 'email', 'label' => 'E-mail do Remetente', 'type' => 'string'],
            'mail.from_name' => ['value' => 'Habitaweb', 'group' => 'email', 'label' => 'Nome do Remetente', 'type' => 'string'],
            'notify.leads' => ['value' => '1', 'group' => 'notifications', 'label' => 'Notificar Novos Leads', 'type' => 'boolean'],
            'notify.whatsapp_leads' => ['value' => '0', 'group' => 'notifications', 'label' => 'Notificar via WhatsApp', 'type' => 'boolean'],
            'notify.subscription_expiry' => ['value' => '1', 'group' => 'notifications', 'label' => 'Alertas de Vencimento', 'type' => 'boolean'],
            'notify.low_limits' => ['value' => '1', 'group' => 'notifications', 'label' => 'Alertas de Limite de Imóveis', 'type' => 'boolean'],
        ];

        foreach ($essential as $key => $data) {
            if (!$model->where('key', $key)->first()) {
                $model->insert(array_merge(['key' => $key, 'created_at' => $now], $data));
            }
        }
        
        // Limpa o cache para garantir que as novas configs apareçam imediatamente
        cache()->delete('app_settings_global');
    }

    public function update()
    {
        if (!$this->request->isAJAX() && $this->request->getMethod() == 'get') {
             return redirect()->to('admin/settings');
        }

        $data = $this->request->getPost();
        $files = $this->request->getFiles();
        
        // Remove campos que não são configurações
        unset($data['csrf_test_name']);
        unset($data['_method']);

        $model = model('App\Models\SettingModel');
        $allSettings = $model->findAll();
        $validKeys = array_column($allSettings, 'key');
        
        // CRIAR MAPA DE TRADUÇÃO (PHP Key -> DB Key)
        $keyMap = [];
        foreach ($validKeys as $dbKey) {
            $phpKey = str_replace('.', '_', $dbKey);
            $keyMap[$phpKey] = $dbKey;
        }
        
        $updatedCount = 0;

        // Processa campos de texto/cor
        foreach ($data as $postedKey => $value) {
            // Verifica se a chave postada existe no mapa
            if (isset($keyMap[$postedKey])) {
                $realDbKey = $keyMap[$postedKey];
                
                $updateResult = $model->update($realDbKey, ['value' => $value]);
                if ($updateResult) $updatedCount++;
            } else {
                // Tenta ver se a chave já é válida diretamente
                if (in_array($postedKey, $validKeys)) {
                    $model->update($postedKey, ['value' => $value]);
                    $updatedCount++;
                }
            }
        }
        
        // Processa Uploads de Imagens
        foreach ($files as $postedKey => $file) {
             if (isset($keyMap[$postedKey])) {
                $realDbKey = $keyMap[$postedKey];
                
                if ($file->isValid() && !$file->hasMoved()) {
                    // Upload
                    $newName = $file->getRandomName();
                    $folder = 'uploads/settings';
                    $file->move(FCPATH . $folder, $newName);
                    $relativePath = $folder . '/' . $newName;
                    
                    $model->update($realDbKey, ['value' => $relativePath]);
                    $updatedCount++;
                }
            }
        }

        // Limpa cache global de settings se existir
        cache()->delete('app_settings_global');

        if ($this->request->isAJAX()) {
             return $this->response->setJSON([
                 'success' => true, 
                 'message' => "Configurações salvas: $updatedCount alteração(ões)."
             ]);
        }
        
        return redirect()->to('admin/settings')->with('success', 'Configurações atualizadas com sucesso.');
    }
}
