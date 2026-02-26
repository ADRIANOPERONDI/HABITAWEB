<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAccountIdToCoupons extends Migration
{
    public function up()
    {
        $fields = [
            'account_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'id'
            ]
        ];

        $this->forge->addColumn('coupons', $fields);
        
        // Add foreign key constraint if you want strict referential integrity
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'SET NULL', 'CASCADE', 'coupons_account_id_fk');
        
        // Add index to speed up lookups
        $this->forge->addKey('account_id', false);
        $this->forge->processIndexes('coupons');
    }

    public function down()
    {
        $this->forge->dropForeignKey('coupons', 'coupons_account_id_fk');
        $this->forge->dropColumn('coupons', 'account_id');
    }
}
