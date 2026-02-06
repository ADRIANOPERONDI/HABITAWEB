<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddWhatsAppHubToAccounts extends Migration
{
    public function up()
    {
        $fields = [
            'whatsapp_hub_config' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'Configuração dos botões do Hub de WhatsApp (JSON)',
            ],
            'whatsapp_messages_config' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'Templates de mensagens padrão por operação/tipo (JSON)',
            ],
        ];

        $this->forge->addColumn('accounts', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('accounts', ['whatsapp_hub_config', 'whatsapp_messages_config']);
    }
}
