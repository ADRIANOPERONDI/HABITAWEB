<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sinalizador de "sincronizar agora" que o cron consome, não a requisição web.
 *
 * O botão "Sincronizar agora" do painel rodava o sync de forma síncrona
 * dentro do próprio request web, sem `set_time_limit`. Contra um catálogo do
 * tamanho normal de uma imobiliária (baixando as fotos de cada imóvel), isso
 * estoura o `max_execution_time` do PHP com um Fatal Error — que não é um
 * `\Throwable` capturável, então o `finally` que libera as travas de cache
 * (`IntegrationSyncService::run()`) nunca roda, e as travas ficam presas até
 * expirar sozinhas (até 30 min), sem nenhuma rota ou comando para destravar.
 *
 * Não reaproveita `last_sync_at` como sinalizador de prioridade:
 * `SyncCursor::fromIntegration()` usa esse campo diretamente como corte
 * incremental, e `IntegrationSyncService::pauseVanished()` usa `empty()`
 * dele para decidir se a rodada foi um scan completo — qualquer valor
 * artificial ali quebra os dois. Também não reaproveita `sync_cursor`
 * (jsonb): esse campo já tem semântica própria, de retomada do conector por
 * finalidade, não de agendamento.
 */
class AddSyncPriorityToAccountIntegrations extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('sync_priority_requested_at', 'account_integrations')) {
            $this->forge->addColumn('account_integrations', [
                'sync_priority_requested_at' => [
                    'type'    => 'TIMESTAMP',
                    'null'    => true,
                    'comment' => 'Tenant pediu "sincronizar agora" — cron trata esta integração antes das demais e limpa o campo ao consumir',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('sync_priority_requested_at', 'account_integrations')) {
            $this->forge->dropColumn('account_integrations', 'sync_priority_requested_at');
        }
    }
}
