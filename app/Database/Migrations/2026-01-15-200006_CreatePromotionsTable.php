<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePromotionsTable extends Migration
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
            'tipo_promocao' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
            ],
            'data_inicio' => [
                'type' => 'TIMESTAMP',
            ],
            'data_fim' => [
                'type' => 'TIMESTAMP',
            ],
            'ativo' => [
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
        $this->forge->addForeignKey('property_id', 'properties', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('promotions');
    }

    public function down()
    {
        $this->forge->dropTable('promotions');
    }
}
