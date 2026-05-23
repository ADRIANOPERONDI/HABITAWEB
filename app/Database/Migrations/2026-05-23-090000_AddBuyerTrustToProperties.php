<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddBuyerTrustToProperties extends Migration
{
    public function up()
    {
        $propertyFields = [
            'documentation_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'default'    => 'NAO_INFORMADO',
            ],
            'registry_number' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
            ],
            'accepts_financing' => [
                'type'    => 'BOOLEAN',
                'default' => false,
            ],
            'has_debt' => [
                'type'    => 'BOOLEAN',
                'default' => false,
            ],
            'debt_notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'occupation_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'default'    => 'NAO_INFORMADO',
            ],
            'condominium_rules' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'included_items' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'inspection_notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'buyer_warning_notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'trust_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'default'    => 'INCOMPLETO',
            ],
            'trust_reviewed_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
            'trust_reviewed_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
        ];

        foreach ($propertyFields as $field => $definition) {
            if (! $this->db->fieldExists($field, 'properties')) {
                $this->forge->addColumn('properties', [$field => $definition]);
            }
        }

        if (! $this->db->tableExists('property_trust_events')) {
            $this->forge->addField([
                'id' => [
                    'type'           => 'BIGINT',
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'property_id' => [
                    'type'     => 'BIGINT',
                    'unsigned' => true,
                ],
                'user_id' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => true,
                ],
                'event_type' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 80,
                ],
                'old_value' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'new_value' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'notes' => [
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
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('property_id');
            $this->forge->addKey('event_type');
            $this->forge->addForeignKey('property_id', 'properties', 'id', 'CASCADE', 'CASCADE');
            $this->forge->addForeignKey('user_id', 'users', 'id', 'SET NULL', 'CASCADE');
            $this->forge->createTable('property_trust_events', true);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('property_trust_events')) {
            $this->forge->dropTable('property_trust_events');
        }

        $fields = [
            'documentation_status',
            'registry_number',
            'accepts_financing',
            'has_debt',
            'debt_notes',
            'occupation_status',
            'condominium_rules',
            'included_items',
            'inspection_notes',
            'buyer_warning_notes',
            'trust_status',
            'trust_reviewed_at',
            'trust_reviewed_by',
        ];

        foreach ($fields as $field) {
            if ($this->db->fieldExists($field, 'properties')) {
                $this->forge->dropColumn('properties', $field);
            }
        }
    }
}
