<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Índice parcial para getOverdueAccountIds() (PaymentTransactionModel), que
 * roda em toda busca pública em produção: filtra por status IN (...) +
 * due_date <= X. Sem índice em due_date, cada busca fazia seq scan em
 * payment_transactions. Parcial (WHERE status IN ...) porque só transações
 * nesses status interessam à query — índice menor, mais quente no cache.
 * Também beneficia isAccountBlockedByOverdue() (mesmo filtro + account_id).
 */
class AddOverdueTransactionsIndex extends Migration
{
    public function up()
    {
        $this->db->query(
            "CREATE INDEX IF NOT EXISTS idx_payment_tx_overdue
             ON payment_transactions (due_date, account_id)
             WHERE status IN ('PENDING', 'AWAITING_PAYMENT', 'OVERDUE')"
        );
    }

    public function down()
    {
        $this->db->query('DROP INDEX IF EXISTS idx_payment_tx_overdue');
    }
}
