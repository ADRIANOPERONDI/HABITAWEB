<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddGracePeriodToCoupons extends Migration
{
    public function up()
    {
        $fields = [
            'carencia_valor' => [
                'type'       => 'INT',
                'constraint' => 11,
                'default'    => 0,
                'null'       => false,
            ],
            'carencia_tipo' => [
                'type'       => 'VARCHAR',
                'constraint' => '20',
                'default'    => 'DAYS', // DAYS, MONTHS, YEARS
                'null'       => false,
            ],
        ];

        $this->forge->addColumn('coupons', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('coupons', 'carencia_valor');
        $this->forge->dropColumn('coupons', 'carencia_tipo');
    }
}
