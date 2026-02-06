<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCurationFieldsToProperties extends Migration
{
    public function up()
    {
        $fields = [
            'last_validated_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
                'after' => 'status'
            ],
            'quality_warnings' => [
                'type' => 'JSON',
                'null' => true,
                'after' => 'last_validated_at',
                'comment' => 'Stores arrays of warnings like price_suspicious, duplicate'
            ],
            'moderation_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'default'    => 'APPROVED', // APPROVED, PENDING_REVIEW, REJECTED
                'after'      => 'quality_warnings'
            ],
            'auto_paused' => [
                'type'       => 'BOOLEAN',
                'default'    => false,
                'after'      => 'moderation_status'
            ],
            'auto_paused_reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'after'      => 'auto_paused'
            ],
            'duplicate_signature' => [ // Hash for duplicate detection
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'auto_paused_reason'
            ]
        ];

        $this->forge->addColumn('properties', $fields);
        
        // Index for duplicate search
        $this->db->query('CREATE INDEX idx_properties_duplicate_signature ON properties(duplicate_signature)');
    }

    public function down()
    {
        $this->forge->dropColumn('properties', [
            'last_validated_at', 
            'quality_warnings', 
            'moderation_status', 
            'auto_paused', 
            'auto_paused_reason',
            'duplicate_signature'
        ]);
    }
}
