<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Histórico de execuções de sincronização.
 *
 * Sem isto o tenant não tem como saber por que o catálogo dele parou de
 * atualizar — e o suporte não tem como responder. Cada rodada abre uma linha em
 * RUNNING e a fecha em SUCCESS / PARTIAL / ERROR com os contadores.
 *
 * PARTIAL é o caso do limite de plano estourado: continuou atualizando o que já
 * existia, mas parou de criar imóvel novo.
 */
class CreateIntegrationSyncRunsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'account_integration_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            // Não usar o nome "trigger": é palavra reservada no MySQL.
            'trigger_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'cron',
                'comment'    => 'cron ou manual (botão "Sincronizar agora")',
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'RUNNING',
                'comment'    => 'RUNNING, SUCCESS, PARTIAL, ERROR',
            ],
            'started_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'finished_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'total_fetched' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
            ],
            'created_count' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
            ],
            'updated_count' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
            ],
            'skipped_count' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
                'comment'  => 'payload_hash igual — nada mudou na origem',
            ],
            'paused_count' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
                'comment'  => 'Sumiram do catálogo da origem e foram pausados aqui',
            ],
            'images_count' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
            ],
            'error_count' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
            ],
            'error_message' => [
                'type'    => 'TEXT',
                'null'    => true,
                'comment' => 'Erro fatal da rodada, ou resumo dos erros por item',
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['account_integration_id', 'started_at']);

        $this->forge->addForeignKey('account_integration_id', 'account_integrations', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('integration_sync_runs');
    }

    public function down()
    {
        $this->forge->dropTable('integration_sync_runs');
    }
}
