<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateLeadEventsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'lead_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'evento' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'payload' => [
                'type' => 'JSONB',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('lead_id', 'leads', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('lead_events');
    }

    public function down()
    {
        $this->forge->dropTable('lead_events');
    }
}
