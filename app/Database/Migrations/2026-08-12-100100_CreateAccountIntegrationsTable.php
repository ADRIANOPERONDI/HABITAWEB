<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Integração contratada por um tenant.
 *
 * Uma linha por (conta, conector). É aqui que mora o estado da integração —
 * se está ligada, se o último teste de conexão passou, quando foi o último sync
 * e até onde ele chegou. As credenciais ficam em `account_integration_configs`,
 * separadas porque são cifradas e nunca devem sair num SELECT casual.
 */
class CreateAccountIntegrationsTable extends Migration
{
    public function up()
    {
        $jsonType = $this->db->DBDriver === 'Postgre' ? 'JSONB' : 'JSON';

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'account_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'comment'  => 'Conta (tenant) dona da integração',
            ],
            'provider_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'comment'    => 'integration_providers.code',
            ],
            'is_active' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'comment' => 'Sync automático ligado. Só vira true depois de um teste de conexão OK',
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'PENDING',
                'comment'    => 'PENDING, CONNECTED, ERROR, PAUSED',
            ],
            'last_test_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'last_test_message' => [
                'type'    => 'TEXT',
                'null'    => true,
                'comment' => 'Resultado legível do último "Testar conexão" — nunca contém credencial',
            ],
            'last_sync_at' => [
                'type'    => 'TIMESTAMP',
                'null'    => true,
                'comment' => 'Início do último sync concluído. Base do corte incremental por updatedAt',
            ],
            'sync_cursor' => [
                'type'    => $jsonType,
                'null'    => true,
                'comment' => 'Estado de retomada por finalidade (último offset / updatedAt visto)',
            ],
            'settings' => [
                'type'    => $jsonType,
                'null'    => true,
                'comment' => 'Preferências do tenant: finalidades, status inicial, importar imagens…',
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
        $this->forge->addUniqueKey(['account_id', 'provider_code']);
        $this->forge->addKey('is_active');
        // O comando de sync varre os ativos ordenando por last_sync_at ASC.
        $this->forge->addKey('last_sync_at');

        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('account_integrations');
    }

    public function down()
    {
        $this->forge->dropTable('account_integrations');
    }
}
