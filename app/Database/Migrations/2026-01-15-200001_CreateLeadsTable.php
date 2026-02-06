<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateLeadsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'property_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'account_id_anunciante' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'user_id_responsavel' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'nome_visitante' => [
                'type'       => 'VARCHAR',
                'constraint' => 200,
                'null'       => true,
            ],
            'telefone_visitante' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
            ],
            'email_visitante' => [
                'type'       => 'VARCHAR',
                'constraint' => 200,
                'null'       => true,
            ],
            'mensagem' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'origem' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
                'null'       => true,
            ],
            'tipo_lead' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'default'    => 'NOVO',
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
        $this->forge->addForeignKey('property_id', 'properties', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('account_id_anunciante', 'accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id_responsavel', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('leads');
    }

    public function down()
    {
        $this->forge->dropTable('leads');
    }
}
