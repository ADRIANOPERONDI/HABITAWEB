<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLivenessToAccounts extends Migration
{
    public function up()
    {
        $this->forge->addColumn('accounts', [
            'liveness_data' => [
                'type' => 'TEXT', // Will store JSON with image paths
                'null' => true,
                'after' => 'verification_notes'
            ]
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('accounts', 'liveness_data');
    }
}
