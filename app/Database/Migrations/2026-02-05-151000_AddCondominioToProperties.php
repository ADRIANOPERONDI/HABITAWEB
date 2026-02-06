<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCondominioToProperties extends Migration
{
    public function up()
    {
        $fields = [
            'condominio' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
                'null'       => true,
                'after'      => 'rua'
            ],
        ];

        $this->forge->addColumn('properties', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('properties', 'condominio');
    }
}
