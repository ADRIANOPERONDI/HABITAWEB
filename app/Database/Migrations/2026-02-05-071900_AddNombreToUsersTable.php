<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddNomeToUsersTable extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'nome' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'after'      => 'username',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'nome');
    }
}
