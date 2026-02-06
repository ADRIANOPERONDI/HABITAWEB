<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePropertyFavoritesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'       => 'INT',
                'unsigned'   => true,
                'null'       => true, // Permite anônimos no futuro (cookie)
            ],
            'property_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('property_id', 'properties', 'id', 'CASCADE', 'CASCADE');
        // Evita duplicidade de favorito do mesmo usuario pro mesmo imovel
        $this->forge->addUniqueKey(['user_id', 'property_id']);
        
        $this->forge->createTable('property_favorites');
    }

    public function down()
    {
        $this->forge->dropTable('property_favorites');
    }
}
