<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAccountIdToUsersTable extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('users')) {
            return;
        }

        if (! $this->db->fieldExists('account_id', 'users')) {
            $this->forge->addColumn('users', [
                'account_id' => [
                    'type'       => 'BIGINT',
                    'unsigned'   => true,
                    'null'       => true,
                    'after'      => 'id',
                ],
            ]);
        }

        // Nota: O CI Forge às vezes tem dificuldade em adicionar FK em tabelas existentes no Postgres.
        // Aqui usamos SQL idempotente para evitar erro em reexecuções.
        $this->db->query("DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'users_account_id_foreign'
    ) THEN
        ALTER TABLE users
        ADD CONSTRAINT users_account_id_foreign
        FOREIGN KEY (account_id)
        REFERENCES accounts(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE;
    END IF;
END
$$;");
    }

    public function down()
    {
        if (! $this->db->tableExists('users')) {
            return;
        }

        $this->db->query("DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'users_account_id_foreign'
    ) THEN
        ALTER TABLE users DROP CONSTRAINT users_account_id_foreign;
    END IF;
END
$$;");

        if ($this->db->fieldExists('account_id', 'users')) {
            $this->forge->dropColumn('users', 'account_id');
        }
    }
}
