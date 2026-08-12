<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cobrança por lead fechado nos imóveis vindos de integração.
 *
 * Duas tabelas:
 *
 * - integration_commission_rules: quanto cobrar, por tenant e por tipo de
 *   negócio. Regra com account_id nulo é o padrão da plataforma, usado quando
 *   o tenant não tem acordo próprio. Percentual ou valor fixo, com piso e teto.
 *   É o que permite mudar o modelo comercial sem tocar em código.
 *
 * - integration_commissions: a comissão apurada de cada lead fechado, com o
 *   ciclo PENDING -> APPROVED -> INVOICED -> PAID. O unique em lead_id garante
 *   que reabrir e refechar um lead não gere cobrança dobrada.
 *
 * O gatilho é o fechamento de lead que já existe (LeadService::updateStatus com
 * CONCLUIDO + closing_value).
 */
class CreateIntegrationCommissionTables extends Migration
{
    public function up()
    {
        // ------------------------------------------------------------ regras
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'account_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
                'comment'  => 'NULL = regra padrão da plataforma, para quem não tem acordo próprio',
            ],
            'provider_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'comment'    => 'NULL = vale para qualquer conector',
            ],
            'tipo_negocio' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'comment'    => 'VENDA, ALUGUEL, TEMPORADA. NULL = qualquer um',
            ],
            'model' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'default'    => 'PERCENT',
                'comment'    => 'PERCENT (sobre o valor de fechamento) ou FIXED',
            ],
            'value' => [
                'type'       => 'NUMERIC',
                'constraint' => '14,4',
                'default'    => 0,
                'comment'    => 'Percentual (ex.: 10.0000 = 10%) ou valor fixo em reais',
            ],
            'min_value' => [
                'type'       => 'NUMERIC',
                'constraint' => '14,2',
                'null'       => true,
                'comment'    => 'Piso da comissão',
            ],
            'max_value' => [
                'type'       => 'NUMERIC',
                'constraint' => '14,2',
                'null'       => true,
                'comment'    => 'Teto da comissão',
            ],
            'is_active' => [
                'type'    => 'BOOLEAN',
                'default' => true,
            ],
            'valid_from' => ['type' => 'DATE', 'null' => true],
            'valid_to'   => ['type' => 'DATE', 'null' => true],
            'notes'      => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMP', 'null' => true],
            'updated_at' => ['type' => 'TIMESTAMP', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['account_id', 'is_active']);
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('integration_commission_rules');

        // -------------------------------------------------------- comissões
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
            'provider_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'lead_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'comment'  => 'Lead que fechou o negócio',
            ],
            'property_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'rule_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
                'comment'  => 'Regra aplicada, para auditoria do cálculo',
            ],
            'tipo_negocio' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
            ],
            'base_value' => [
                'type'       => 'NUMERIC',
                'constraint' => '14,2',
                'default'    => 0,
                'comment'    => 'closing_value do lead — a base do cálculo',
            ],
            'commission_value' => [
                'type'       => 'NUMERIC',
                'constraint' => '14,2',
                'default'    => 0,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'PENDING',
                'comment'    => 'PENDING, APPROVED, INVOICED, PAID, CANCELLED',
            ],
            'payment_transaction_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
                'comment'  => 'Cobrança gerada no gateway',
            ],
            'closed_at'   => ['type' => 'TIMESTAMP', 'null' => true],
            'approved_at' => ['type' => 'TIMESTAMP', 'null' => true],
            'invoiced_at' => ['type' => 'TIMESTAMP', 'null' => true],
            'paid_at'     => ['type' => 'TIMESTAMP', 'null' => true],
            'notes'       => ['type' => 'TEXT', 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMP', 'null' => true],
            'updated_at'  => ['type' => 'TIMESTAMP', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        // Um lead gera no máximo uma comissão: reabrir e refechar não cobra duas vezes.
        $this->forge->addUniqueKey('lead_id');
        $this->forge->addKey(['account_id', 'status']);
        $this->forge->addKey('closed_at');

        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('lead_id', 'leads', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('integration_commissions');
    }

    public function down()
    {
        $this->forge->dropTable('integration_commissions');
        $this->forge->dropTable('integration_commission_rules');
    }
}
