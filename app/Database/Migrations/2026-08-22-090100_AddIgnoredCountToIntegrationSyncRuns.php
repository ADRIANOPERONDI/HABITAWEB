<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Conta, separado de erro, quantos itens da rodada foram deliberadamente
 * não importados (categoria sem de/para confirmado, por exemplo).
 *
 * Sem isso, "ignorado por falta de mapeamento" e "falhou de verdade" se
 * misturam em `error_count`, e o resumo do sync não deixa claro pro tenant
 * que o catálogo dele tem categorias esperando confirmação em
 * /admin/integracoes/{code}/mapeamentos — parece defeito quando é apenas
 * revisão pendente.
 */
class AddIgnoredCountToIntegrationSyncRuns extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('ignored_count', 'integration_sync_runs')) {
            $this->forge->addColumn('integration_sync_runs', [
                'ignored_count' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'default'  => 0,
                    'after'    => 'skipped_count',
                    'comment'  => 'Itens sem mapeamento confirmado — não importados de propósito, não é erro',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('ignored_count', 'integration_sync_runs')) {
            $this->forge->dropColumn('integration_sync_runs', 'ignored_count');
        }
    }
}
