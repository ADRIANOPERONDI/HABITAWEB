<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddHighlightFieldsToProperties extends Migration
{
    public function up()
    {
        $fields = [
            'highlight_level' => [
                'type'       => 'INT',
                'constraint' => 1,
                'default'    => 0,
                'comment'    => '0:None, 1:Silver, 2:Gold, 3:Diamond'
            ],
            'highlight_expires_at' => [
                'type' => 'TIMESTAMP',
                'null' => true,
            ],
        ];

        $this->forge->addColumn('properties', $fields);
        
        // Add index for faster sorting/filtering
        $this->db->query("CREATE INDEX IF NOT EXISTS idx_properties_highlight ON properties(highlight_level, highlight_expires_at)");
    }

    public function down()
    {
        $this->forge->dropColumn('properties', ['highlight_level', 'highlight_expires_at']);
        $this->db->query("DROP INDEX IF EXISTS idx_properties_highlight");
    }
}
