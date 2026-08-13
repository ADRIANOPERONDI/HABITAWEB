<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Remove `plans.destaques_mensais`.
 *
 * Havia duas colunas disputando o mesmo conceito. `limite_turbo_mensal` era a
 * que o sistema aplicava de fato (PropertyService::canMarkAsDestaque a lia);
 * `destaques_mensais` só alimentava uma trava de downgrade e três textos de
 * tela — e as duas divergiam nos dados semeados, então a tela prometia um
 * número e o sistema aplicava outro.
 *
 * A trava de downgrade já saiu do SubscriptionController e as views passaram a
 * ler Plan::turbosIncluidos(), por isso a coluna pode cair agora. Migration
 * separada da mudança de código de propósito: entre um deploy e outro, a tela
 * de assinatura continua funcionando em qualquer ordem.
 */
class DropDestaquesMensaisFromPlans extends Migration
{
    public function up()
    {
        $this->db->resetDataCache();

        if ($this->db->fieldExists('destaques_mensais', 'plans')) {
            $this->forge->dropColumn('plans', 'destaques_mensais');
        }
    }

    public function down()
    {
        $this->db->resetDataCache();

        if (! $this->db->fieldExists('destaques_mensais', 'plans')) {
            $this->forge->addColumn('plans', [
                'destaques_mensais' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                    'default'    => 0,
                ],
            ]);
        }
    }
}
