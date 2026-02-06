<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateApiKeysTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'account_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'comment' => 'Conta dona da chave de API',
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'comment' => 'Nome identificador da chave (ex: App Mobile, Site Principal)',
            ],
            'key_hash' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'comment' => 'Hash bcrypt da chave (nunca armazenar plain text)',
            ],
            'prefix' => [
                'type' => 'VARCHAR',
                'constraint' => 10,
                'comment' => 'Primeiros caracteres visíveis (ex: pk_live_abc...)',
            ],
            'last_four' => [
                'type' => 'VARCHAR',
                'constraint' => 4,
                'comment' => 'Últimos 4 caracteres para identificação',
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'active',
                'comment'    => 'Status da chave',
            ],
            'rate_limit_per_hour' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 1000,
                'comment' => 'Limite de requisições por hora',
            ],
            'last_used_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
                'comment' => 'Última utilização da chave',
            ],
            'last_used_ip' => [
                'type' => 'VARCHAR',
                'constraint' => 45,
                'null' => true,
                'comment' => 'Último IP que utilizou a chave',
            ],
            'expires_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
                'comment' => 'Data de expiração (opcional)',
            ],
            'created_by_user_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'comment' => 'Usuário que criou a chave (geralmente Super Admin)',
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('account_id');
        $this->forge->addKey('status');
        $this->forge->addKey(['deleted_at']);
        
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('created_by_user_id', 'users', 'id', 'CASCADE', 'SET NULL');

        $this->forge->createTable('api_keys');
    }

    public function down()
    {
        $this->forge->dropTable('api_keys');
    }
}
