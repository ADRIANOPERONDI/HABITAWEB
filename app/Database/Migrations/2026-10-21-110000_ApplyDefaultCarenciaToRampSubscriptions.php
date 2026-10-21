<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Contas que entraram automaticamente na rampa antes da carência negociada
 * (cadastro pelo site, ou `spark assinatura:ativar`) recebem a condição
 * padrão acordada com o cliente: 6 meses sem mensalidade e sem leads, mais 6
 * só com leads, contando da data em que entraram (`ramp_started_at`). Sai a
 * faixa de 50% do mês 7 ao 12, que ninguém tinha negociado.
 *
 * Só toca assinatura com `ramp_started_at` e sem prazos ainda — o
 * administrador ajusta caso a caso depois, pelo painel.
 */
class ApplyDefaultCarenciaToRampSubscriptions extends Migration
{
    public function up()
    {
        $this->db->table('subscriptions')
            ->where('ramp_started_at IS NOT NULL', null, false)
            ->where('isencao_total_meses IS NULL', null, false)
            ->update(['isencao_total_meses' => 6, 'isencao_mensalidade_meses' => 6]);
    }

    public function down()
    {
        // Irreversível de propósito: depois do up() não há como distinguir os
        // prazos aplicados aqui dos que o administrador gravou à mão.
    }
}
