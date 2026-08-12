<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Marca qual rodada tocou cada vínculo pela última vez.
 *
 * O sync detecta "sumiu da origem" procurando os vínculos que a rodada atual
 * não visitou. A primeira versão comparava last_synced_at com o instante em que
 * a rodada começou, e isso tem um furo: a coluna é TIMESTAMP com precisão de
 * segundo, então duas rodadas dentro do mesmo segundo deixam
 * `last_synced_at < inicio_da_rodada` falso e nenhum imóvel é detectado como
 * sumido.
 *
 * Comparar o id da rodada é exato em qualquer driver, e ainda deixa o histórico
 * legível: dá para ver em qual execução cada imóvel foi visto pela última vez.
 */
class AddLastSyncRunToPropertyExternalRefs extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('last_sync_run_id', 'property_external_refs')) {
            $this->forge->addColumn('property_external_refs', [
                'last_sync_run_id' => [
                    'type'     => 'BIGINT',
                    'unsigned' => true,
                    'null'     => true,
                    'comment'  => 'integration_sync_runs.id da última rodada que viu este imóvel na origem',
                ],
            ]);
        }

        $this->forge->addKey('last_sync_run_id');
        $this->db->query(
            'CREATE INDEX IF NOT EXISTS idx_refs_provider_run
             ON property_external_refs (provider_code, last_sync_run_id)'
        );
    }

    public function down()
    {
        $this->db->query('DROP INDEX IF EXISTS idx_refs_provider_run');

        if ($this->db->fieldExists('last_sync_run_id', 'property_external_refs')) {
            $this->forge->dropColumn('property_external_refs', 'last_sync_run_id');
        }
    }
}
