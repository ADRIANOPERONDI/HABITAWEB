<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Vínculo entre um imóvel do Habitaweb e o registro dele na plataforma externa.
 *
 * Por que não reusar properties.external_id: aquela coluna é single-valued e já
 * é a chave de upsert do import de parceiro (/api/v1/import/properties), com
 * índice único parcial em (account_id, external_id). Se o mesmo tenant usasse o
 * import de parceiro E o Simob, dois external_id iguais colidiriam e um sync
 * sobrescreveria o imóvel do outro. Uma tabela à parte, com provider_code na
 * chave, resolve isso sem tocar em nada do caminho de import existente.
 *
 * payload_hash existe para o sync incremental: se o hash do payload normalizado
 * não mudou, o imóvel é pulado sem nenhum UPDATE no banco.
 */
class CreatePropertyExternalRefsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'property_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'account_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'comment'  => 'Desnormalizado de properties para permitir a unique por tenant',
            ],
            'provider_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'external_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'comment'    => 'ID do imóvel na plataforma externa (Simob: campo id)',
            ],
            'external_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
                'comment'    => 'Código visível ao corretor (Simob: campo codigo). Só para exibição',
            ],
            'external_updated_at' => [
                'type'    => 'TIMESTAMP',
                'null'    => true,
                'comment' => 'updatedAt informado pela origem — base do corte incremental',
            ],
            'payload_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'comment'    => 'SHA-256 do payload normalizado; igual = nada mudou, pula o UPDATE',
            ],
            'last_synced_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
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
        $this->forge->addUniqueKey(['account_id', 'provider_code', 'external_id']);
        $this->forge->addKey('property_id');
        // O sync marca como PAUSED quem não apareceu na rodada: varre por
        // (integração, last_synced_at < início da rodada).
        $this->forge->addKey(['provider_code', 'last_synced_at']);

        $this->forge->addForeignKey('property_id', 'properties', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('property_external_refs');
    }

    public function down()
    {
        $this->forge->dropTable('property_external_refs');
    }
}
