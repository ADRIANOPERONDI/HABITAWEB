<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Credenciais da integração, por tenant.
 *
 * Chave/valor em vez de colunas fixas porque cada conector pede um conjunto
 * diferente de campos (o Simob quer base_url + token; outro pode querer
 * client_id + client_secret). O painel renderiza a partir de
 * `integration_providers.config_schema` e grava aqui.
 *
 * `config_value` guarda o valor CIFRADO (Services::encrypter()) quando
 * is_sensitive = true. É cifragem reversível de propósito: ao contrário de
 * api_keys.key_hash, que é bcrypt porque só precisa VALIDAR uma chave de
 * entrada, aqui o token precisa ser reproduzido em toda chamada de saída.
 */
class CreateAccountIntegrationConfigsTable extends Migration
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
            'config_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'comment'    => 'Chave definida no config_schema do conector: base_url, token…',
            ],
            'config_value' => [
                'type'    => 'TEXT',
                'null'    => true,
                'comment' => 'Cifrado com Services::encrypter() quando is_sensitive = true',
            ],
            'is_sensitive' => [
                'type'    => 'BOOLEAN',
                'default' => false,
                'comment' => 'Se true: cifrado no banco, mascarado no painel, nunca logado',
            ],
            'last_four' => [
                'type'       => 'VARCHAR',
                'constraint' => 4,
                'null'       => true,
                'comment'    => 'Últimos 4 caracteres do segredo, para exibir ••••1234 sem decifrar',
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
        $this->forge->addUniqueKey(['account_integration_id', 'config_key']);

        $this->forge->addForeignKey('account_integration_id', 'account_integrations', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('account_integration_configs');
    }

    public function down()
    {
        $this->forge->dropTable('account_integration_configs');
    }
}
