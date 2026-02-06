<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePropertyReportsTable extends Migration
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
            'user_id' => [ // Optional, if logged in
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'type' => [
                'type'       => 'VARCHAR',
                'constraint' => 30, // FRAUD, DUPLICATE, SOLD, WRONG_INFO, OUTDATED
                'default'    => 'WRONG_INFO'
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20, // PENDING, RESOLVED, REJECTED
                'default'    => 'PENDING'
            ],
            'resolution_notes' => [
                'type' => 'TEXT',
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

        $this->forge->addPrimaryKey('id');
        $this->forge->addForeignKey('property_id', 'properties', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'SET NULL');
        
        $this->forge->createTable('property_reports', true);
    }

    public function down()
    {
        $this->forge->dropTable('property_reports');
    }
}
