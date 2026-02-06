<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePropertyMediaTable extends Migration
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
            'tipo' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'FOTO',
            ],
            'url' => [
                'type' => 'TEXT',
            ],
            'ordem' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'principal' => [
                'type'    => 'BOOLEAN',
                'default' => false,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('property_id', 'properties', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('property_media', true);
    }

    public function down()
    {
        $this->forge->dropTable('property_media');
    }
}
