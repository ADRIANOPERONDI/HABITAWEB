<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAuditLogs extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'actor_user_id' => ['type' => 'INT', 'null' => true],   // quem executou (null = sistema/anônimo)
            'account_id'    => ['type' => 'INT', 'null' => true],   // conta afetada/contexto
            'action'        => ['type' => 'VARCHAR', 'constraint' => 80],  // ex.: user.role_changed
            'entity_type'   => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'entity_id'     => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'ip_address'    => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'metadata'      => ['type' => 'TEXT', 'null' => true], // JSON com detalhes (antes/depois, etc.)
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('action');
        $this->forge->addKey('account_id');
        $this->forge->addKey('actor_user_id');
        $this->forge->addKey('created_at');

        $this->forge->createTable('audit_logs', true);
    }

    public function down()
    {
        $this->forge->dropTable('audit_logs', true);
    }
}
