<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAreaPrivativaToProperties extends Migration
{
    public function up()
    {
        $fields = [
            'area_privativa' => [
                'type'       => 'NUMERIC',
                'constraint' => '10,2',
                'null'       => true,
            ],
        ];

        $this->forge->addColumn('properties', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('properties', 'area_privativa');
    }
}
