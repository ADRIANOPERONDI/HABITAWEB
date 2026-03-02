<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCarenciaDiasToPlans extends Migration
{
    public function up()
    {
        $this->forge->addColumn('plans', [
            'carencia_dias' => [
                'type' => 'INT',
                'constraint' => 5,
                'default' => 3,
                'null' => false,
                'after' => 'ativo'
            ]
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('plans', 'carencia_dias');
    }
}
