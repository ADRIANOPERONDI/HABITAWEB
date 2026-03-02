<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddVerificationToAccountsAndProperties extends Migration
{
    public function up()
    {
        // Campos para Verificação de Identidade em Accounts
        $this->forge->addColumn('accounts', [
            'is_verified' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],
            'verification_status' => [
                'type' => 'VARCHAR',
                'constraint' => '20',
                'default' => 'NONE', // NONE, PENDING, APPROVED, REJECTED
            ],
            'id_front' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
                'null' => true,
            ],
            'id_back' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
                'null' => true,
            ],
            'selfie' => [
                'type' => 'VARCHAR',
                'constraint' => '255',
                'null' => true,
            ],
            'verification_notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);

        // Campos para Verificação de Imóveis (Anti-Fraude)
        $this->forge->addColumn('properties', [
            'is_verified' => [
                'type' => 'BOOLEAN',
                'default' => false,
            ],
            'verification_status' => [
                'type' => 'VARCHAR',
                'constraint' => '20',
                'default' => 'PENDING', // PENDING, APPROVED, REJECTED
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('accounts', ['is_verified', 'verification_status', 'id_front', 'id_back', 'selfie', 'verification_notes']);
        $this->forge->dropColumn('properties', ['is_verified', 'verification_status']);
    }
}
