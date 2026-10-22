<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Registro dos avisos de vencimento já enviados (decisão do cliente: aviso
 * antes do vencimento, no dia, durante o atraso e na suspensão, por e-mail).
 * Uma linha por fatura e etapa — o comando diário consulta isto para nunca
 * mandar o mesmo aviso duas vezes, mesmo rodando mais de uma vez no dia.
 */
class CreateBillingRemindersTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'                     => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'payment_transaction_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'account_id'             => ['type' => 'BIGINT', 'unsigned' => true],
            'etapa'                  => ['type' => 'VARCHAR', 'constraint' => 20],
            'email_enviado'          => ['type' => 'BOOLEAN', 'default' => false],
            'sent_at'                => ['type' => 'TIMESTAMP', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['payment_transaction_id', 'etapa'], 'uq_billing_reminders_tx_etapa');
        $this->forge->createTable('billing_reminders');
    }

    public function down()
    {
        $this->forge->dropTable('billing_reminders', true);
    }
}
