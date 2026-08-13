<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Carteira de créditos de lead (Ouro/Diamante) — append-only.
 *
 * Sem tabela de "saldo": o saldo de um período é
 * `SUM(CREDITO) - SUM(DEBITO)` filtrado por `account_id, periodo`, uma query
 * indexada. Denormalizar saldo em `accounts` criaria um segundo lugar para
 * ficar errado toda vez que uma concessão ou consumo esquecesse de atualizar
 * os dois. `tipo` é sempre CREDITO ou DEBITO — "expiração" é um DEBITO com
 * `origem = EXPIRACAO`, não um terceiro tipo, para a fórmula do saldo não
 * precisar de um caso especial.
 *
 * UNIQUE parcial em (account_id, periodo) para origem=PLANO_MENSAL é o que
 * torna `creditos:conceder` idempotente: rodar duas vezes no mesmo mês não
 * duplica a concessão.
 */
class CreateLeadCreditLedgerTable extends Migration
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
            'tipo' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'comment'    => 'CREDITO ou DEBITO',
            ],
            'origem' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'comment'    => 'PLANO_MENSAL, CONSUMO_FATURA, EXPIRACAO, AJUSTE_MANUAL',
            ],
            'amount' => [
                'type'       => 'NUMERIC',
                'constraint' => '14,2',
                'comment'    => 'Sempre positivo; o sinal vem de tipo',
            ],
            'periodo' => [
                'type'    => 'DATE',
                'comment' => 'Mes de competencia (primeiro dia) a que este lancamento pertence',
            ],
            'reference_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
                'comment'    => 'Ex.: payment_transactions, quando o debito paga uma fatura',
            ],
            'reference_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'notes'      => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMP', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['account_id', 'periodo']);
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('lead_credit_ledger');

        $this->db->query(
            "CREATE UNIQUE INDEX lead_credit_ledger_plano_mensal_unq
             ON lead_credit_ledger (account_id, periodo)
             WHERE origem = 'PLANO_MENSAL' AND tipo = 'CREDITO'"
        );
    }

    public function down()
    {
        $this->forge->dropTable('lead_credit_ledger');
    }
}
