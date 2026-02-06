<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateClientsTable extends Migration
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
                'type'       => 'BIGINT',
                'unsigned'   => true,
                'null'       => false,
            ],
            'nome' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'email' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'telefone' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
            ],
            'cpf_cnpj' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
            ],
            'tipo_cliente' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'default'    => 'PROPRIETARIO',
            ],
            'notas' => [
                'type' => 'TEXT',
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
        $this->forge->createTable('clients');

        // Adicionar coluna client_id na tabela properties
        $this->forge->addColumn('properties', [
            'client_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'account_id'
            ]
        ]);
        
        $this->db->query('ALTER TABLE properties ADD CONSTRAINT fk_properties_client_id FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL ON UPDATE CASCADE');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE properties DROP CONSTRAINT IF EXISTS fk_properties_client_id');
        $this->forge->dropColumn('properties', 'client_id');
        $this->forge->dropTable('clients');
    }
}
