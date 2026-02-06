<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePropertyAlertsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 200,
            ],
            'nome' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'whatsapp' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
            ],
            'filtros' => [
                'type' => 'TEXT', // JSON com os filtros da busca
                'null' => false,
            ],
            'frequencia' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'DIARIO', // IMEDIATO, DIARIO, SEMANAL
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'ATIVO', // ATIVO, PAUSADO, CANCELADO
            ],
            'last_sent_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('email');
        $this->forge->addKey('status');
        $this->forge->createTable('property_alerts');
    }

    public function down()
    {
        $this->forge->dropTable('property_alerts');
    }
}
