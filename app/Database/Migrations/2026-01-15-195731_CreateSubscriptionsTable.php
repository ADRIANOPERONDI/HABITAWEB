<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSubscriptionsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'account_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'plan_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'default'    => 'ATIVA',
            ],
            'data_inicio' => [
                'type' => 'DATE',
            ],
            'data_fim' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'proximo_pagamento' => [
                'type' => 'DATE',
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
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('plan_id', 'plans', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('subscriptions');
    }

    public function down()
    {
        $this->forge->dropTable('subscriptions');
    }
}
