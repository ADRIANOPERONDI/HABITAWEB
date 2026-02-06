<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePaymentProfilesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'account_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'gateway' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'ASAAS', // ASAAS, STRIPE, MP
            ],
            'external_token' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
            'last_digits' => [
                'type' => 'VARCHAR',
                'constraint' => 4,
                'null' => true,
            ],
            'brand' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => true,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'ACTIVE',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('payment_profiles');
    }

    public function down()
    {
        $this->forge->dropTable('payment_profiles');
    }
}
