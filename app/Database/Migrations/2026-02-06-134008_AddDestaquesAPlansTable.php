<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDestaquesAPlansTable extends Migration
{
    public function up()
    {
        // Verifica se a coluna já existe antes de adicionar
        if (!$this->db->fieldExists('destaques_mensais', 'plans')) {
            $fields = [
                'destaques_mensais' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'default'    => 0,
                    'null'       => false,
                    'after'      => 'limite_fotos_por_imovel'
                ],
            ];

            try {
                $this->forge->addColumn('plans', $fields);
            } catch (\Throwable $e) {
                // Ignora quando a coluna já existe em ambientes com schema divergente.
            }
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('destaques_mensais', 'plans')) {
            $this->forge->dropColumn('plans', 'destaques_mensais');
        }
    }
}
