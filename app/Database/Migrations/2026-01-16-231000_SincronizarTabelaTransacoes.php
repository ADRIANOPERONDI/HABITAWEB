<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SincronizarTabelaTransacoes extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        
        // 1. Renomear colunas existentes se houver
        if ($db->fieldExists('external_id', 'payment_transactions') && !$db->fieldExists('gateway_transaction_id', 'payment_transactions')) {
            $this->forge->modifyColumn('payment_transactions', [
                'external_id' => [
                    'name' => 'gateway_transaction_id',
                    'type' => 'VARCHAR',
                    'constraint' => 100,
                ],
            ]);
        }

        if ($db->fieldExists('method', 'payment_transactions') && !$db->fieldExists('payment_method', 'payment_transactions')) {
            $this->forge->modifyColumn('payment_transactions', [
                'method' => [
                    'name' => 'payment_method',
                    'type' => 'VARCHAR',
                    'constraint' => 50,
                ],
            ]);
        }

        // 2. Adicionar novas colunas se não existirem
        $fields = [
            'gateway' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'ASAAS',
                'after'      => 'account_id'
            ],
            'currency' => [
                'type'       => 'VARCHAR',
                'constraint' => 3,
                'default'    => 'BRL',
                'after'      => 'amount'
            ],
            'metadata' => [
                'type' => 'JSONB',
                'null' => true,
                'after' => 'payment_method'
            ],
            'subscription_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'id'
            ]
        ];

        foreach ($fields as $fieldName => $fieldConfig) {
            if (!$db->fieldExists($fieldName, 'payment_transactions')) {
                $this->forge->addColumn('payment_transactions', [$fieldName => $fieldConfig]);
            }
        }
    }

    public function down()
    {
        // Reverter não é estritamente necessário para este fix, mas boa prática
        $this->forge->dropColumn('payment_transactions', ['gateway', 'currency', 'metadata', 'subscription_id']);
        
        $this->forge->modifyColumn('payment_transactions', [
            'gateway_transaction_id' => [
                'name' => 'external_id',
                'type' => 'VARCHAR',
                'constraint' => 100,
            ],
            'payment_method' => [
                'name' => 'method',
                'type' => 'VARCHAR',
                'constraint' => 20,
            ],
        ]);
    }
}
