<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Permite gravar um vínculo mesmo quando o imóvel nunca chegou a existir.
 *
 * Hoje, se `upsertProperty()` falha na validação (bairro/cidade ausentes, por
 * exemplo), a exceção sobe antes de `upsertRef()` rodar — o item fica sem
 * vínculo, sem `payload_hash` e sem `external_updated_at`, então o corte
 * incremental não tem como saber que aquele item já foi tentado. Toda rodada
 * busca o detalhe de novo, falha de novo, para sempre.
 *
 * `property_id` passa a aceitar NULL para representar exatamente esse caso:
 * "vínculo com a origem existe, mas não virou imóvel publicável" —
 * `last_error` guarda o motivo pro painel de execuções.
 */
class AddLastErrorToPropertyExternalRefs extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE property_external_refs ALTER COLUMN property_id DROP NOT NULL');
        $this->db->query('ALTER TABLE property_external_refs DROP CONSTRAINT IF EXISTS property_external_refs_property_id_foreign');
        $this->db->query(
            'ALTER TABLE property_external_refs
                ADD CONSTRAINT property_external_refs_property_id_foreign
                FOREIGN KEY (property_id) REFERENCES properties (id)
                ON DELETE CASCADE ON UPDATE CASCADE'
        );

        if (! $this->db->fieldExists('last_error', 'property_external_refs')) {
            $this->forge->addColumn('property_external_refs', [
                'last_error' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 500,
                    'null'       => true,
                    'comment'    => 'Motivo da última falha ao tentar publicar este item; NULL quando property_id não é nulo',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('last_error', 'property_external_refs')) {
            $this->forge->dropColumn('property_external_refs', 'last_error');
        }

        $this->db->query('DELETE FROM property_external_refs WHERE property_id IS NULL');
        $this->db->query('ALTER TABLE property_external_refs ALTER COLUMN property_id SET NOT NULL');
    }
}
