<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMoreFieldsToPlans extends Migration
{
    public function up()
    {
        $fields = [
            'limite_fotos_por_imovel' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 10,
                'after'      => 'limite_imoveis_ativos'
            ],
            'destaques_mensais' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
                'after'      => 'limite_fotos_por_imovel'
            ],
        ];

        $this->forge->addColumn('plans', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('plans', ['limite_fotos_por_imovel', 'destaques_mensais']);
    }
}
