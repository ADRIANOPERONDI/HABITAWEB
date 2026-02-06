<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePromotionPackagesTable extends Migration
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
                'constraint' => 120,
            ],
            'tipo_promocao' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
            ],
            'duracao_dias' => [
                'type' => 'INT',
            ],
            'preco' => [
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
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('promotion_packages');
    }

    public function down()
    {
        $this->forge->dropTable('promotion_packages');
    }
}
