<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Trava de sincronização como linha do banco, não como chave de cache.
 *
 * A trava vivia em `cache($lockKey)` (IntegrationSyncService::LOCK_TTL,
 * 1800s). Dois problemas reais: (1) um Fatal Error de PHP (max_execution_time,
 * por exemplo) não é `\Throwable` — não passa por nenhum catch/finally — e a
 * chave de cache fica presa até expirar sozinha, sem nenhum jeito de
 * destravar antes disso; (2) "ler o cache, decidir que está livre, gravar"
 * não é atômico contra duas execuções batendo ao mesmo tempo (cron + clique
 * manual, por exemplo).
 *
 * Uma coluna com `UPDATE ... WHERE (sync_locked_until IS NULL OR
 * sync_locked_until < now())` resolve os dois: é uma escrita condicional
 * atômica no próprio banco (1 linha afetada = adquiriu), funciona igual em
 * processo web ou CLI, e um `register_shutdown_function` consegue liberá-la
 * mesmo quando o processo morre por Fatal Error.
 */
class AddSyncLockToAccountIntegrations extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('sync_locked_until', 'account_integrations')) {
            $this->forge->addColumn('account_integrations', [
                'sync_locked_until' => [
                    'type'    => 'TIMESTAMP',
                    'null'    => true,
                    'comment' => 'Rodada em andamento até este instante — NULL ou no passado = livre para adquirir',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('sync_locked_until', 'account_integrations')) {
            $this->forge->dropColumn('account_integrations', 'sync_locked_until');
        }
    }
}
