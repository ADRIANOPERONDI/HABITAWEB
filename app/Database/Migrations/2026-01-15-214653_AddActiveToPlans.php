<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddActiveToPlans extends Migration
{
    public function up()
    {
        $this->forge->addColumn('plans', [
            'ativo' => [
                'type'       => 'BOOLEAN',
                'default'    => true,
                'after'      => 'nome', // Opcional
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('plans', 'ativo');
    }
}
