<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCouponsTables extends Migration
{
    public function up()
    {
        // Tabela de Cupons
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'code' => [
                'type'       => 'VARCHAR',
                'constraint' => '50',
                'unique'     => true,
            ],
            'description' => [
                'type'       => 'VARCHAR',
                'constraint' => '255',
                'null'       => true,
            ],
            'discount_type' => [
                'type'       => 'VARCHAR',
                'constraint' => '20',
                'default'    => 'percent',
            ],
            'discount_value' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
            ],
            'max_uses' => [
                'type'       => 'INT',
                'null'       => true,
                'comment'    => 'Limite global de usos. Null = Ilimitado',
            ],
            'used_count' => [
                'type'       => 'INT',
                'default'    => 0,
            ],
            'valid_from' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'valid_until' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'is_active' => [
                'type'    => 'BOOLEAN',
                'default' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ]
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('coupons');

        // Tabela de Uso de Cupons (Log)
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'coupon_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'account_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true
            ],
            'transaction_id' => [ // Relaciona com payment_transactions
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true
            ],
            'discount_applied' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
            ],
            'used_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ]
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('coupon_id', 'coupons', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'SET NULL', 'CASCADE');
        // $this->forge->addForeignKey('transaction_id', 'payment_transactions', 'id', 'SET NULL', 'CASCADE'); // Opcional, evitar locking circular for now
        $this->forge->createTable('coupon_usages');
    }

    public function down()
    {
        $this->forge->dropTable('coupon_usages');
        $this->forge->dropTable('coupons');
    }
}
