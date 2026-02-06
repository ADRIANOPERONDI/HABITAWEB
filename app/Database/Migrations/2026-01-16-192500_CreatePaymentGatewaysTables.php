<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePaymentGatewaysTables extends Migration
{
    public function up()
    {
        // Tabela: payment_gateways
        $this->forge->addField([
            'id' => [
                'type' => 'SERIAL',
                'unsigned' => true
            ],
            'code' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'unique' => true
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 100
            ],
            'class_name' => [
                'type' => 'VARCHAR',
                'constraint' => 255
            ],
            'is_active' => [
                'type' => 'BOOLEAN',
                'default' => false
            ],
            'is_primary' => [
                'type' => 'BOOLEAN',
                'default' => false
            ],
            'logo_url' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true
            ],
            'supported_methods' => [
                'type' => 'JSONB',
                'null' => true
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP')
            ],
            'updated_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP')
            ]
        ]);
        
        $this->forge->addKey('id', true);
        $this->forge->createTable('payment_gateways');
        
        // Tabela: payment_gateway_configs
        $this->forge->addField([
            'id' => [
                'type' => 'SERIAL',
                'unsigned' => true
            ],
            'gateway_id' => [
                'type' => 'INT',
                'unsigned' => true
            ],
            'config_key' => [
                'type' => 'VARCHAR',
                'constraint' => 100
            ],
            'config_value' => [
                'type' => 'TEXT',
                'null' => true
            ],
            'config_type' => [
                'type' => 'VARCHAR',
                'constraint' => '50',
                'default' => 'string'
            ],
            'is_sensitive' => [
                'type' => 'BOOLEAN',
                'default' => false
            ],
            'display_order' => [
                'type' => 'INT',
                'default' => 0
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP')
            ]
        ]);
        
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('gateway_id', 'payment_gateways', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addUniqueKey(['gateway_id', 'config_key']);
        $this->forge->createTable('payment_gateway_configs');
        
        // Adicionar colunas em payment_transactions
        $fields = [
            'gateway_code' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
                'after' => 'method'
            ],
            'gateway_customer_id' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'gateway_code'
            ],
            'gateway_subscription_id' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'gateway_customer_id'
            ],
            'metadata' => [
                'type' => 'JSONB',
                'null' => true,
                'after' => 'gateway_subscription_id'
            ]
        ];
        
        $this->forge->addColumn('payment_transactions', $fields);
    }

    public function down()
    {
        // Remover colunas de payment_transactions
        $this->forge->dropColumn('payment_transactions', ['gateway_code', 'gateway_customer_id', 'gateway_subscription_id', 'metadata']);
        
        // Dropar tabelas
        $this->forge->dropTable('payment_gateway_configs', true);
        $this->forge->dropTable('payment_gateways', true);
    }
}
