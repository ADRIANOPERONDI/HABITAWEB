<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePaymentTables extends Migration
{
    public function up()
    {
        // 1. Create payment_transactions table
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'account_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
                'null'       => true, // Can be null for initial callback sometimes, but usually linked
            ],
            'external_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'method' => [
                'type'       => 'VARCHAR',
                'constraint' => 20, // PIX, BOLETO, CREDIT_CARD
                'default'    => 'PIX',
            ],
            'amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'default'    => 0.00,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 50, // PENDING, CONFIRMED, RECEIVED, FAILED, REFUNDED
                'default'    => 'PENDING',
            ],
            'type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50, // SUBSCRIPTION, TURBO
                'default'    => 'SUBSCRIPTION',
            ],
            'reference_id' => [
                'type'       => 'BIGINT', // ID of subscription or promotion linked
                'null'       => true,
            ],
            'description' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'pdf_url' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'invoice_url' => [
                'type'       => 'TEXT',
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'paid_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('account_id');
        $this->forge->addKey('external_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('payment_transactions');

        // 2. Add columns to subscriptions table
        $fields = [
            'asaas_subscription_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'after'      => 'status'
            ],
            'asaas_customer_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'after'      => 'account_id'
            ],
            'payment_method' => [
                'type'       => 'VARCHAR',
                'constraint' => 20, // PIX, BOLETO, CREDIT_CARD
                'null'       => true,
                'after'      => 'price'
            ],
            'next_billing_date' => [
                'type' => 'DATE',
                'null' => true,
                'after' => 'data_fim'
            ],
        ];
        
        // Check if column exists before adding (safety)
        $db = \Config\Database::connect();
        if (!$db->fieldExists('asaas_subscription_id', 'subscriptions')) {
            $this->forge->addColumn('subscriptions', $fields);
        }
        
         // 3. Create payment_methods table (optional for now, but good for credit cards)
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'account_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
            ],
            'token' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'last_digits' => [
                'type'       => 'VARCHAR',
                'constraint' => 4,
            ],
            'brand' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('payment_methods');
    }

    public function down()
    {
        $this->forge->dropTable('payment_transactions', true);
        $this->forge->dropTable('payment_methods', true);
        
        $this->forge->dropColumn('subscriptions', ['asaas_subscription_id', 'asaas_customer_id', 'payment_method', 'next_billing_date']);
    }
}
