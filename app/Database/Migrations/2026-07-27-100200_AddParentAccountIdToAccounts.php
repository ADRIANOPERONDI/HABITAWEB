<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Coluna de subconta (imobiliária → corretor).
 *
 * App\Controllers\Api\V1\AccountController já referenciava $account->parent_account_id
 * em show/create/update/delete, mas a coluna nunca existiu no banco. Como o CI4
 * devolve null para atributo inexistente, toda comparação "null != $currentAccountId"
 * era verdadeira e a feature inteira de subcontas era código morto (além de
 * AccountModel::$allowedFields descartar o campo no insert).
 */
class AddParentAccountIdToAccounts extends Migration
{
    public function up()
    {
        $this->forge->addColumn('accounts', [
            'parent_account_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
                'comment'  => 'Conta-mãe (imobiliária) quando este registro é uma subconta/corretor',
            ],
        ]);

        $this->db->query('CREATE INDEX idx_accounts_parent ON accounts (parent_account_id)');

        // SET NULL na exclusão: apagar a imobiliária não deve apagar em cascata
        // os corretores — eles viram contas independentes e o suporte decide.
        $this->forge->addForeignKey('parent_account_id', 'accounts', 'id', 'CASCADE', 'SET NULL');
        $this->forge->processIndexes('accounts');
    }

    public function down()
    {
        $this->forge->dropForeignKey('accounts', 'accounts_parent_account_id_foreign');
        $this->db->query('DROP INDEX IF EXISTS idx_accounts_parent');
        $this->forge->dropColumn('accounts', 'parent_account_id');
    }
}
