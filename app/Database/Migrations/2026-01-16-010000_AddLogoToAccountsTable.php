<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLogoToAccountsTable extends Migration
{
    public function up()
    {
        $this->forge->addColumn('accounts', [
            'logo' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'whatsapp'
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('accounts', 'logo');
    }
}
