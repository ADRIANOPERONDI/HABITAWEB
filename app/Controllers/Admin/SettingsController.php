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
            $query->whereNotIn('group', ['email', 'notifications', 'legal', 'about']);
        }

        $settings = $query->findAll();

        // Agrupa pro view
        $grouped = [];
        foreach ($settings as $s) {
            $grouped[$s->group][] = $s;
        }

        return view('Admin/settings/index', [
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

            // Appearance
            'style.logo_url'        => ['value' => 'assets/img/logo.png', 'group' => 'appearance', 'label' => 'Logo Original', 'type' => 'image', 'description' => 'Logo principal do site. Recomendado fundo transparente (PNG).'],
            'style.logo_footer_url' => ['value' => 'assets/img/logo-light.png', 'group' => 'appearance', 'label' => 'Logo Branca', 'type' => 'image', 'description' => 'Versão para fundos escuros (Rodapé e Dashboard).'],
            'style.favicon_url'     => ['value' => 'assets/img/favicon.png', 'group' => 'appearance', 'label' => 'Favicon', 'type' => 'image'],
            'style.primary_color'   => ['value' => '#6366f1', 'group' => 'appearance', 'label' => 'Cor Principal', 'type' => 'color'],
            'style.secondary_color' => ['value' => '#a855f7', 'group' => 'appearance', 'label' => 'Cor Secundária', 'type' => 'color'],
            'style.tertiary_color'  => ['value' => '#10b981', 'group' => 'appearance', 'label' => 'Cor Terciária', 'type' => 'color'],
            'style.logo_height'     => ['value' => '70', 'group' => 'appearance', 'label' => 'Altura da Logo (Público)', 'type' => 'number'],

            // Home Page
            'home.hero_banner'      => ['value' => 'https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=1920&q=80', 'group' => 'home', 'label' => 'Banner Principal', 'type' => 'image'],
            'home.hero_title'       => ['value' => 'Onde você quer morar?', 'group' => 'home', 'label' => 'Título Principal', 'type' => 'string'],
            'home.hero_subtitle'    => ['value' => 'Descubra os melhores imóveis da sua região com quem entende do assunto.', 'group' => 'home', 'label' => 'Subtítulo Principal', 'type' => 'string'],
            'home.cta_banner'       => ['value' => 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?auto=format&fit=crop&w=1200&q=80', 'group' => 'home', 'label' => 'Banner de Anúncio (CTA)', 'type' => 'image'],
            'home.cta_title'        => ['value' => 'Anuncie seu imóvel para milhares de pessoas', 'group' => 'home', 'label' => 'Título de Anúncio', 'type' => 'string'],
            'home.cta_subtitle'     => ['value' => 'Seja você um proprietário, corretor ou imobiliária, o nosso portal é o lugar certo para fechar negócio.', 'group' => 'home', 'label' => 'Subtítulo de Anúncio', 'type' => 'string'],

            // Footer
            'footer.description'    => ['value' => 'O portal imobiliário mais completo da região. Conectando pessoas aos seus sonhos.', 'group' => 'footer', 'label' => 'Texto do Rodapé', 'type' => 'text'],
            'footer.address'        => ['value' => 'Av. Principal, 123 - Centro', 'group' => 'footer', 'label' => 'Endereço', 'type' => 'string'],
            'site.copyright'        => ['value' => '&copy; ' . date('Y') . ' Habitaweb. Todos os direitos reservados.', 'group' => 'footer', 'label' => 'Copyright', 'type' => 'string'],

            // SEO Default
            'seo.title'             => ['value' => 'Habitaweb', 'group' => 'seo', 'label' => 'Nome da Marca (Título)', 'type' => 'string'],
            'seo.tagline'           => ['value' => 'Encontre seu lugar', 'group' => 'seo', 'label' => 'Slogan / Tagline', 'type' => 'string'],

            // About Us (apenas superadmin)
            'about.hero_title'       => ['value' => 'Sobre a nossa empresa', 'group' => 'about', 'label' => 'Título Principal', 'type' => 'string', 'description' => 'Título de destaque no topo da página Sobre Nós.'],
            'about.hero_subtitle'    => ['value' => 'Conheça nossa história, propósito e o compromisso que temos com cada cliente.', 'group' => 'about', 'label' => 'Subtítulo Principal', 'type' => 'text', 'description' => 'Texto curto de apoio logo abaixo do título principal.'],
            'about.hero_image'       => ['value' => '', 'group' => 'about', 'label' => 'Imagem de Destaque', 'type' => 'image', 'description' => 'Imagem principal exibida no topo da página Sobre Nós.'],
            'about.story_title'      => ['value' => 'Nossa história', 'group' => 'about', 'label' => 'Título da História', 'type' => 'string', 'description' => 'Título da seção principal com a apresentação da empresa.'],
            'about.story_content'    => ['value' => '', 'group' => 'about', 'label' => 'Conteúdo da História', 'type' => 'richtext', 'description' => 'Conteúdo principal da página Sobre Nós com formatação completa.'],
            'about.mission_title'    => ['value' => 'Missão', 'group' => 'about', 'label' => 'Título da Missão', 'type' => 'string', 'description' => 'Título do bloco de missão.'],
            'about.mission_text'     => ['value' => '', 'group' => 'about', 'label' => 'Texto da Missão', 'type' => 'text', 'description' => 'Explique a missão da empresa de forma objetiva.'],
            'about.vision_title'     => ['value' => 'Visão', 'group' => 'about', 'label' => 'Título da Visão', 'type' => 'string', 'description' => 'Título do bloco de visão.'],
            'about.vision_text'      => ['value' => '', 'group' => 'about', 'label' => 'Texto da Visão', 'type' => 'text', 'description' => 'Explique a visão da empresa de forma objetiva.'],
            'about.values_title'     => ['value' => 'Nossos valores', 'group' => 'about', 'label' => 'Título dos Valores', 'type' => 'string', 'description' => 'Título da seção que lista os valores da empresa.'],
            'about.values_content'   => ['value' => '', 'group' => 'about', 'label' => 'Conteúdo dos Valores', 'type' => 'richtext', 'description' => 'Liste e descreva os valores institucionais com texto rico.'],
            'about.stats_experience' => ['value' => '10', 'group' => 'about', 'label' => 'Anos de Experiência', 'type' => 'number', 'description' => 'Número exibido na estatística de experiência.'],
            'about.stats_clients'    => ['value' => '500', 'group' => 'about', 'label' => 'Clientes Atendidos', 'type' => 'number', 'description' => 'Número exibido na estatística de clientes.'],
            'about.stats_properties' => ['value' => '1000', 'group' => 'about', 'label' => 'Imóveis Anunciados', 'type' => 'number', 'description' => 'Número exibido na estatística de imóveis.'],
            'about.cta_title'        => ['value' => 'Vamos conversar sobre o seu próximo imóvel?', 'group' => 'about', 'label' => 'Título do CTA', 'type' => 'string', 'description' => 'Título da chamada final da página Sobre Nós.'],
            'about.cta_text'         => ['value' => 'Entre em contato com a nossa equipe ou anuncie conosco para alcançar mais oportunidades.', 'group' => 'about', 'label' => 'Texto do CTA', 'type' => 'text', 'description' => 'Texto de apoio da chamada final.'],

            // Legal (apenas superadmin)
            'legal.terms_of_use'     => ['value' => '', 'group' => 'legal', 'label' => 'Termos de Uso', 'type' => 'richtext', 'description' => 'Conteúdo completo da página de Termos de Uso do portal.'],
            'legal.privacy_policy'   => ['value' => '', 'group' => 'legal', 'label' => 'Política de Privacidade', 'type' => 'richtext', 'description' => 'Conteúdo completo da página de Política de Privacidade do portal.'],
        ];

        foreach ($essential as $key => $data) {
            $existing = $model->where('key', $key)->first();
            if (!$existing) {
                $model->insert(array_merge(['key' => $key, 'created_at' => $now], $data));
            } else {
                // Sincroniza labels e descrições se eles mudarem no código
                $model->update($key, [
                    'label'       => $data['label'],
                    'description' => $data['description'] ?? $existing->description,
                    'group'       => $data['group'],
                    'type'        => $data['type'],
                ]);
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
