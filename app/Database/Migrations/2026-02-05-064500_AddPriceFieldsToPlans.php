<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPriceFieldsToPlans extends Migration
{
    public function up()
    {
        $fields = [
            'preco_trimestral' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'default'    => 0.00,
                'after'      => 'preco_mensal'
            ],
            'preco_semestral' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'default'    => 0.00,
                'after'      => 'preco_trimestral'
            ],
            'preco_anual' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'default'    => 0.00,
                'after'      => 'preco_semestral'
            ],
        ];

        $this->forge->addColumn('plans', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('plans', ['preco_trimestral', 'preco_semestral', 'preco_anual']);
    }
}
