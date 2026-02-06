<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePropertiesTable extends Migration
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
            ],
            'user_id_responsavel' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'tipo_negocio' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'tipo_imovel' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'titulo' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'descricao' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'preco' => [
                'type'       => 'NUMERIC',
                'constraint' => '14,2',
                'default'    => 0,
            ],
            'valor_condominio' => [
                'type'       => 'NUMERIC',
                'constraint' => '14,2',
                'null'       => true,
            ],
            'iptu' => [
                'type'       => 'NUMERIC',
                'constraint' => '14,2',
                'null'       => true,
            ],
            'area_total' => [
                'type'       => 'NUMERIC',
                'constraint' => '10,2',
                'null'       => true,
            ],
            'area_construida' => [
                'type'       => 'NUMERIC',
                'constraint' => '10,2',
                'null'       => true,
            ],
            'quartos' => [
                'type' => 'INT',
                'null' => true,
            ],
            'banheiros' => [
                'type' => 'INT',
                'null' => true,
            ],
            'vagas' => [
                'type' => 'INT',
                'null' => true,
            ],
            'cep' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
            ],
            'estado' => [
                'type'       => 'VARCHAR',
                'constraint' => 2,
                'null'       => true,
            ],
            'cidade' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
            ],
            'bairro' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
            ],
            'rua' => [
                'type'       => 'VARCHAR',
                'constraint' => 200,
                'null'       => true,
            ],
            'numero' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'null'       => true,
            ],
            'complemento' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'latitude' => [
                'type'       => 'NUMERIC',
                'constraint' => '10,7',
                'null'       => true,
            ],
            'longitude' => [
                'type'       => 'NUMERIC',
                'constraint' => '10,7',
                'null'       => true,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'default'    => 'DRAFT',
            ],
            'visitas_count' => [
                'type'    => 'BIGINT',
                'default' => 0,
            ],
            'leads_count' => [
                'type'    => 'BIGINT',
                'default' => 0,
            ],
            'score_qualidade' => [
                'type'    => 'INT',
                'default' => 0,
            ],
            'publicado_em' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'atualizado_em' => [
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
            'deleted_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('user_id_responsavel', 'users', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('properties', true);
    }

    public function down()
    {
        $this->forge->dropTable('properties');
    }
}
