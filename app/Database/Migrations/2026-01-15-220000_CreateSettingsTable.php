<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSettingsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'key' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'value' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'group' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'general',
            ],
            'type' => [
                'type'       => 'VARCHAR', // string, text, image, color, boolean
                'constraint' => 20,
                'default'    => 'string',
            ],
            'label' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => true,
            ],
            'description' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ]);
        $this->forge->addPrimaryKey('key');
        
        // Se a tabela system_settings já existir (de migrações anteriores malsucedidas), removemos para garantir a nova estrutura.
        // Mas como estamos em dev e o usuário disse que está uma bosta, vamos zerar.
        $this->forge->dropTable('system_settings', true);
        $this->forge->createTable('system_settings', true);

        // Seeds iniciais expandidos
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');
        
        $data = [
            // GERAL
            [
                'key' => 'site.name', 
                'value' => 'Habitaweb', 
                'group' => 'general', 
                'type' => 'string', 
                'label' => 'Nome do Site', 
                'description' => 'O nome que aparecerá no título da aba do navegador e no rodapé.',
                'created_at' => $now
            ],
            [
                'key' => 'site.phone', 
                'value' => '(11) 99999-9999', 
                'group' => 'general', 
                'type' => 'string', 
                'label' => 'Telefone de Contato', 
                'description' => 'Telefone principal exibido no cabeçalho e páginas de contato.',
                'created_at' => $now
            ],
            [
                'key' => 'site.email', 
                'value' => 'contato@habitaweb.com.br', 
                'group' => 'general', 
                'type' => 'string', 
                'label' => 'E-mail de Suporte', 
                'description' => 'Endereço de e-mail oficial para recebimento de leads e contatos.',
                'created_at' => $now
            ],
            
            // SEO
            [
                'key' => 'seo.title', 
                'value' => 'Habitaweb - Encontre seu lugar', 
                'group' => 'seo', 
                'type' => 'string', 
                'label' => 'Título SEO (Meta Tag)', 
                'description' => 'Título otimizado para motores de busca (Google). Máximo 60 caracteres recomendado.',
                'created_at' => $now
            ],
            [
                'key' => 'seo.description', 
                'value' => 'Encontre casas e apartamentos para comprar ou alugar na nossa região com facilidade.', 
                'group' => 'seo', 
                'type' => 'text', 
                'label' => 'Descrição SEO (Meta Tag)', 
                'description' => 'Resumo do site que aparece nos resultados do Google. Entre 150-160 caracteres.',
                'created_at' => $now
            ],
            [
                'key' => 'seo.keywords', 
                'value' => 'imoveis, casa, apartamento, aluguel, venda, corretor', 
                'group' => 'seo', 
                'type' => 'text', 
                'label' => 'Palavras-chave', 
                'description' => 'Termos relevantes separados por vírgula.',
                'created_at' => $now
            ],
            [
                'key' => 'seo.google_analytics', 
                'value' => '', 
                'group' => 'seo', 
                'type' => 'string', 
                'label' => 'Google Analytics ID', 
                'description' => 'Código de acompanhamento (ex: G-XXXXXXXXXX) para monitorar acessos.',
                'created_at' => $now
            ],

            // APARÊNCIA
            [
                'key' => 'style.primary_color', 
                'value' => '#0d6efd', 
                'group' => 'appearance', 
                'type' => 'color', 
                'label' => 'Cor Principal', 
                'description' => 'A cor predominante do sistema (botões, links ativos e detalhes).',
                'created_at' => $now
            ],
            [
                'key' => 'style.logo_url', 
                'value' => '', 
                'group' => 'appearance', 
                'type' => 'image', 
                'label' => 'URL da Logomarca', 
                'description' => 'Endereço da imagem da sua logo. Recomendado fundo transparente (PNG).',
                'created_at' => $now
            ],
            [
                'key' => 'style.favicon_url', 
                'value' => '', 
                'group' => 'appearance', 
                'type' => 'image', 
                'label' => 'URL do Favicon', 
                'description' => 'Ícone pequeno que aparece na aba do navegador. Geralmente 32x32px.',
                'created_at' => $now
            ],
            
            // REDES SOCIAIS
            [
                'key' => 'social.instagram', 
                'value' => '', 
                'group' => 'social', 
                'type' => 'string', 
                'label' => 'Instagram URL', 
                'description' => 'Link completo para o seu perfil do Instagram.',
                'created_at' => $now
            ],
            [
                'key' => 'social.facebook', 
                'value' => '', 
                'group' => 'social', 
                'type' => 'string', 
                'label' => 'Facebook URL', 
                'description' => 'Link completo para sua página do Facebook.',
                'created_at' => $now
            ],
            [
                'key' => 'social.whatsapp_number', 
                'value' => '', 
                'group' => 'social', 
                'type' => 'string', 
                'label' => 'WhatsApp (Link Direto)', 
                'description' => 'Número com DDD (apenas números) para gerar o link de chat direto.',
                'created_at' => $now
            ],
        ];
        
        $db->table('system_settings')->insertBatch($data);
    }

    public function down()
    {
        $this->forge->dropTable('system_settings');
    }
}
