<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateIntegrationWebhooksTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'account_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
            ],
            'name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100, // Ex: Integração CRM
            ],
            'event' => [
                'type'       => 'VARCHAR',
                'constraint' => 50, // Ex: lead.created, property.active
            ],
            'target_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'secret' => [
                'type'       => 'VARCHAR',
                'constraint' => 64, // Para assinar o payload (HMAC)
                'null'       => true,
            ],
            'is_active' => [
                'type'    => 'BOOLEAN',
                'default' => true,
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
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('integration_webhooks');
    }

    public function down()
    {
        $this->forge->dropTable('integration_webhooks');
    }
}
