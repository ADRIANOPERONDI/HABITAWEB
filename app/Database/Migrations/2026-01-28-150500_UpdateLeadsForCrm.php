<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class UpdateLeadsForCrm extends Migration
{
    public function up()
    {
        $fields = [
            'closed_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'closing_value' => [
                'type'       => 'DECIMAL',
                'constraint' => '15,2',
                'null'       => true,
            ],
            'closing_notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ];

        $this->forge->addColumn('leads', $fields);
        
        // Adicionar campos ao Imóvel para rastrear encerramento
        $propertyFields = [
            'closed_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'closing_lead_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'closing_reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ]
        ];
        
        $this->forge->addColumn('properties', $propertyFields);
        $this->forge->addForeignKey('closing_lead_id', 'leads', 'id', 'SET NULL', 'SET NULL');
    }

    public function down()
    {
        $this->forge->dropColumn('leads', ['closed_at', 'closing_value', 'closing_notes']);
        $this->forge->dropColumn('properties', ['closed_at', 'closing_lead_id', 'closing_reason']);
    }
}
