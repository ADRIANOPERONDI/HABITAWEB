<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Fatura única mensal (decisão do cliente): a plataforma, e não mais a
 * recorrência nativa da Asaas, emite todo dia 1 uma cobrança com a
 * mensalidade do mês e os leads do mês anterior, vencendo dia 10.
 *
 * `mensalidade_paga_ate` é até quando a mensalidade já está paga — a emissão
 * do mês só inclui a mensalidade se ela ainda não cobre o mês. Fica NULL em
 * assinatura que nunca pagou mensalidade (carência, gratuita ou recorrência
 * antiga na Asaas).
 */
class AddMensalidadePagaAteToSubscriptions extends Migration
{
    public function up()
    {
        $this->forge->addColumn('subscriptions', [
            'mensalidade_paga_ate' => [
                'type' => 'DATE', 'null' => true,
                'comment' => 'Ultimo dia coberto pela mensalidade ja paga (fatura unica mensal).',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('subscriptions', 'mensalidade_paga_ate');
    }
}
