<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Snapshot no próprio lead do que a cobrança precisa, para não depender de
 * `properties` mudar de ideia depois. `tipo_negocio` é o caso concreto: o
 * anunciante pode mudar o anúncio de VENDA para ALUGUEL depois do lead
 * recebido, e a fatura de agosto não pode mudar em setembro por causa disso.
 *
 * `ip_address`, `user_agent` e `referrer` alimentam `LeadQualityService`
 * (rajada do mesmo IP, origem suspeita) e a contestação de cobrança.
 */
class AddChargingSnapshotToLeads extends Migration
{
    public function up()
    {
        $this->forge->addColumn('leads', [
            'tipo_negocio' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'comment'    => 'Snapshot de properties.tipo_negocio no momento do lead',
                'after'      => 'property_id',
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
                'after'      => 'mensagem',
            ],
            'user_agent' => [
                'type'    => 'TEXT',
                'null'    => true,
                'after'   => 'ip_address',
            ],
            'referrer' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
                'null'       => true,
                'after'      => 'user_agent',
            ],
        ]);

        $this->db->query(
            "UPDATE leads SET tipo_negocio = properties.tipo_negocio
             FROM properties WHERE properties.id = leads.property_id AND leads.tipo_negocio IS NULL"
        );

        $this->db->query('CREATE INDEX leads_anunciante_created_idx ON leads(account_id_anunciante, created_at)');
    }

    public function down()
    {
        $this->db->query('DROP INDEX IF EXISTS leads_anunciante_created_idx');
        $this->forge->dropColumn('leads', ['tipo_negocio', 'ip_address', 'user_agent', 'referrer']);
    }
}
