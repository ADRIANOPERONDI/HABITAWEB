<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePlansTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'chave' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'unique'     => true,
            ],
            'nome' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'limite_imoveis_ativos' => [
                'type' => 'INT',
                'null' => true,
            ],
            'limite_turbo_mensal' => [
                'type' => 'INT',
                'null' => true,
            ],
            'limite_api_requests_dia' => [
                'type' => 'INT',
                'null' => true,
            ],
            'preco_mensal' => [
                'type'       => 'NUMERIC',
                'constraint' => '10,2',
                'default'    => 0,
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
        $this->forge->createTable('plans');
    }

    public function down()
    {
        $this->forge->dropTable('plans');
    }
}
