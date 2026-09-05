<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Vira o motor de "comissão por negócio fechado" em "cobrança por lead
 * recebido" — reaproveitando a tabela em vez de duplicar a resolução de regra
 * por especificidade e o ciclo PENDING -> APPROVED -> INVOICED -> PAID, que já
 * existem e já são testados. `RENAME TO` é instantâneo no Postgres e preserva
 * linhas, índices e FKs.
 *
 * `origem` marca a proveniência de cada linha: as existentes (todas de
 * negócio fechado) são backfilled para NEGOCIO_FECHADO na mesma migration —
 * é assim que o histórico sobrevive à mudança de semântica sem virar lixo
 * não-classificado.
 *
 * `provider_code`, antes obrigatório (só imóvel de integração gerava
 * comissão), passa a aceitar NULL: a partir de agora todo lead recebido é
 * cobrável, integrado ou não.
 */
class RenameCommissionsToLeadCharges extends Migration
{
    public function up()
    {
        $this->forge->renameTable('integration_commission_rules', 'lead_charge_rules');
        $this->forge->renameTable('integration_commissions', 'lead_charges');

        $this->db->query('ALTER TABLE lead_charges ALTER COLUMN provider_code DROP NOT NULL');

        $this->forge->addColumn('lead_charges', [
            'origem' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'comment'    => 'LEAD_RECEBIDO ou NEGOCIO_FECHADO',
                'after'      => 'tipo_negocio',
            ],
            'periodo' => [
                'type'    => 'DATE',
                'null'    => true,
                'comment' => 'Mes de competencia (primeiro dia), para agrupar no fechamento de ciclo',
            ],
            'credit_applied' => [
                'type'       => 'NUMERIC',
                'constraint' => '14,2',
                'default'    => 0,
                'comment'    => 'Quanto desta cobranca foi coberto por credito da carteira',
            ],
            'contest_deadline' => [
                'type'    => 'TIMESTAMP',
                'null'    => true,
                'comment' => 'Ate quando o tenant pode contestar antes da aprovacao automatica',
            ],
            'dispute_reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'disputed_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'dispute_resolved_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'waived_reason' => [
                'type'    => 'TEXT',
                'null'    => true,
                'comment' => 'Motivo do WAIVED: heuristica de qualidade ou disputa procedente',
            ],
        ]);

        // Historico: tudo que ja existe veio do fechamento manual de negocio.
        $this->db->table('lead_charges')
            ->where('origem', null)
            ->update(['origem' => 'NEGOCIO_FECHADO']);

        $this->db->query('ALTER TABLE lead_charges ALTER COLUMN origem SET NOT NULL');

        $this->db->query(
            "UPDATE lead_charges SET periodo = date_trunc('month', COALESCE(closed_at, created_at))::date WHERE periodo IS NULL"
        );

        $this->forge->addColumn('accounts', [
            'cobranca_leads_isenta' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
                'null'       => false,
                'comment'    => 'Conta isenta de cobranca por lead (ex.: contas internas/superadmin)',
                'after'      => 'parent_account_id',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('accounts', ['cobranca_leads_isenta']);

        $this->forge->dropColumn('lead_charges', [
            'origem', 'periodo', 'credit_applied', 'contest_deadline',
            'dispute_reason', 'disputed_at', 'dispute_resolved_at', 'waived_reason',
        ]);

        $this->db->query('ALTER TABLE lead_charges ALTER COLUMN provider_code SET NOT NULL');

        $this->forge->renameTable('lead_charges', 'integration_commissions');
        $this->forge->renameTable('lead_charge_rules', 'integration_commission_rules');
    }
}
