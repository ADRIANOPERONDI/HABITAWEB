<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWebhookLogs extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'event_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => false,
            ],
            'event_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'payload' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'processed' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
            ],
            'error_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ]);
        
        $this->forge->addKey('id', true);
        $this->forge->addKey('event_type');
        $this->forge->addKey('processed');
        $this->forge->createTable('webhook_logs');
    }

    public function down()
    {
        $this->forge->dropTable('webhook_logs');
    }
}
