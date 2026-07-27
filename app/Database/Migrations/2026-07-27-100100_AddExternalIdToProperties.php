<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Chave de sincronização com a plataforma do parceiro.
 *
 * Sem isso não existe "via de mão dupla": o parceiro que reimportasse o próprio
 * catálogo criava imóveis duplicados a cada sincronização, porque não havia
 * nenhuma chave para casar o registro dele com o nosso. Com external_id o import
 * vira UPSERT — reimportar atualiza em vez de duplicar.
 */
class AddExternalIdToProperties extends Migration
{
    public function up()
    {
        $this->forge->addColumn('properties', [
            'external_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
                'after'      => 'account_id',
                'comment'    => 'ID do imóvel no sistema de origem do parceiro (chave de upsert)',
            ],
            'source' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'comment'    => 'Origem do registro: api, csv, admin',
            ],
            'external_synced_at' => [
                'type'    => 'TIMESTAMP',
                'null'    => true,
                'comment' => 'Última sincronização vinda da plataforma do parceiro',
            ],
        ]);

        // Unicidade por conta. No Postgres usamos índice parcial ignorando
        // soft-deletes, para que reimportar um external_id de um imóvel excluído
        // volte a funcionar. O MySQL não suporta índice parcial, então fica um
        // índice de consulta simples e a unicidade é garantida na aplicação
        // (PropertyImportService resolve por SELECT antes de inserir).
        if ($this->db->DBDriver === 'Postgre') {
            $this->db->query(
                'CREATE UNIQUE INDEX IF NOT EXISTS idx_properties_account_external
                 ON properties (account_id, external_id)
                 WHERE external_id IS NOT NULL AND deleted_at IS NULL'
            );
        } else {
            $this->db->query(
                'CREATE INDEX idx_properties_account_external ON properties (account_id, external_id)'
            );
        }
    }

    public function down()
    {
        if ($this->db->DBDriver === 'Postgre') {
            $this->db->query('DROP INDEX IF EXISTS idx_properties_account_external');
        } else {
            $this->db->query('DROP INDEX idx_properties_account_external ON properties');
        }

        $this->forge->dropColumn('properties', ['external_id', 'source', 'external_synced_at']);
    }
}
