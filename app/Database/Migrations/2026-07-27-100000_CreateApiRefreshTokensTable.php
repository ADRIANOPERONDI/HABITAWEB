<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Refresh tokens da API v1.
 *
 * O access token JWT é stateless por design (validado só por assinatura, sem
 * ida ao banco). Isso significa que ele NÃO pode ser revogado antes de expirar —
 * por isso o TTL curto (1h). A revogação real acontece aqui: sem um refresh
 * token válido nesta tabela, o cliente não consegue renovar e perde o acesso
 * no máximo 1h depois.
 */
class CreateApiRefreshTokensTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'account_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'comment'  => 'Conta (tenant) dona do token',
            ],
            'user_id' => [
                'type'     => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null'     => true,
            ],
            'api_key_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'comment'    => 'API Key que originou o token — permite revogar em cascata',
            ],
            'jti' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'comment'    => 'Identificador único do token (claim jti)',
            ],
            'token_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'comment'    => 'SHA-256 do token — nunca armazenar o token em claro',
            ],
            'expires_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'revoked_at' => [
                'type'    => 'TIMESTAMP',
                'null'    => true,
                'comment' => 'Preenchido no logout ou na rotação do refresh token',
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('jti');
        $this->forge->addKey('account_id');
        $this->forge->addKey('api_key_id');
        $this->forge->addKey('expires_at');

        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('api_refresh_tokens');
    }

    public function down()
    {
        $this->forge->dropTable('api_refresh_tokens');
    }
}
