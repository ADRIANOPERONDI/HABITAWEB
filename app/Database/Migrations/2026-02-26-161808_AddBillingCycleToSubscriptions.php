<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddBillingCycleToSubscriptions extends Migration
{
    public function up()
    {
        $this->forge->addColumn('subscriptions', [
            'billing_cycle' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'MONTHLY',
                'null' => false,
                'after' => 'status'
            ]
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('subscriptions', 'billing_cycle');
    }
}
