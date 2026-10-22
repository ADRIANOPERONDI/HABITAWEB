<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Carência negociada por imobiliária (revisão da área pública, item 11).
 *
 * A rampa de lançamento deixa de ser automática para todo cadastro: só a
 * imobiliária com quem o administrador fechou negócio recebe a condição, com
 * os prazos acordados com ela. Contados a partir de `ramp_started_at`:
 *  - `isencao_total_meses`: meses sem mensalidade e sem cobrança de leads;
 *  - `isencao_mensalidade_meses`: meses seguintes sem mensalidade, mas com os
 *    leads cobrados normalmente.
 * Depois dos dois prazos, plano cheio. NULL nos dois = a assinatura segue a
 * regra antiga das faixas globais (`plan_launch_ramps`), só para não mudar o
 * que estava gravado antes desta migration.
 */
class AddIsencaoNegociadaToSubscriptions extends Migration
{
    public function up()
    {
        $this->forge->addColumn('subscriptions', [
            'isencao_total_meses' => [
                'type' => 'SMALLINT', 'null' => true,
                'comment' => 'Meses (a partir de ramp_started_at) sem mensalidade e sem cobranca de leads.',
            ],
            'isencao_mensalidade_meses' => [
                'type' => 'SMALLINT', 'null' => true,
                'comment' => 'Meses seguintes sem mensalidade, com leads cobrados.',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('subscriptions', ['isencao_total_meses', 'isencao_mensalidade_meses']);
    }
}
