<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMoreFieldsToPlans extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('plans')) {
            return;
        }

        if (! $this->db->fieldExists('limite_fotos_por_imovel', 'plans')) {
            $this->forge->addColumn('plans', [
                'limite_fotos_por_imovel' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'default'    => 10,
                    'after'      => 'limite_imoveis_ativos'
                ],
            ]);
        }

        if (! $this->db->fieldExists('destaques_mensais', 'plans')) {
            $this->forge->addColumn('plans', [
                'destaques_mensais' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'default'    => 0,
                    'after'      => 'limite_fotos_por_imovel'
                ],
            ]);
        }
    }

    public function down()
    {
        if (! $this->db->tableExists('plans')) {
            return;
        }

        foreach (['limite_fotos_por_imovel', 'destaques_mensais'] as $column) {
            if ($this->db->fieldExists($column, 'plans')) {
                $this->forge->dropColumn('plans', $column);
            }
        }
    }
}
