<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAccountIdToUsersTable extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', [
            'account_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'id',
            ],
        ]);

        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'SET NULL');
        // Nota: O CI Forge às vezes tem dificuldade em adicionar FK em tabelas existentes no Postgres.
        // Se houver erro, usaremos raw SQL.
    }

    public function down()
    {
        $this->forge->dropForeignKey('users', 'users_account_id_foreign');
        $this->forge->dropColumn('users', 'account_id');
    }
}
