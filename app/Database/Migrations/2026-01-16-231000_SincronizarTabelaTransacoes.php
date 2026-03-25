<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SincronizarTabelaTransacoes extends Migration
{
    public function up()
    {
        $db = \Config\Database::connect();
        
        // 1. Garantir colunas-alvo da normalização (evita renames frágeis entre ambientes)
        if (! $db->fieldExists('gateway_transaction_id', 'payment_transactions')) {
            try {
                $this->forge->addColumn('payment_transactions', [
                    'gateway_transaction_id' => [
                        'type'       => 'VARCHAR',
                        'constraint' => 100,
                        'null'       => true,
                        'after'      => 'account_id',
                    ],
                ]);
            } catch (\Throwable $e) {
                // Ignora quando a coluna já existe em ambientes com schema divergente.
            }
        }

        if (! $db->fieldExists('payment_method', 'payment_transactions')) {
            try {
                $this->forge->addColumn('payment_transactions', [
                    'payment_method' => [
                        'type'       => 'VARCHAR',
                        'constraint' => 50,
                        'null'       => true,
                        'after'      => 'gateway_transaction_id',
                    ],
                ]);
            } catch (\Throwable $e) {
                // Ignora quando a coluna já existe em ambientes com schema divergente.
            }
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
                try {
                    $this->forge->addColumn('payment_transactions', [$fieldName => $fieldConfig]);
                } catch (\Throwable $e) {
                    // Ignora quando a coluna já existe em ambientes com schema divergente.
                }
            }
        }
    }

    public function down()
    {
        if (! $this->db->tableExists('payment_transactions')) {
            return;
        }

        // Reverter não é estritamente necessário para este fix, mas boa prática
        foreach (['gateway', 'currency', 'metadata', 'subscription_id'] as $column) {
            if ($this->db->fieldExists($column, 'payment_transactions')) {
                $this->forge->dropColumn('payment_transactions', $column);
            }
        }

        if ($this->db->fieldExists('gateway_transaction_id', 'payment_transactions') && ! $this->db->fieldExists('external_id', 'payment_transactions')) {
            $this->forge->modifyColumn('payment_transactions', [
                'gateway_transaction_id' => [
                    'name' => 'external_id',
                    'type' => 'VARCHAR',
                    'constraint' => 100,
                ],
            ]);
        }

        if ($this->db->fieldExists('payment_method', 'payment_transactions') && ! $this->db->fieldExists('method', 'payment_transactions')) {
            $this->forge->modifyColumn('payment_transactions', [
                'payment_method' => [
                    'name' => 'method',
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                ],
            ]);
        }
    }
}
