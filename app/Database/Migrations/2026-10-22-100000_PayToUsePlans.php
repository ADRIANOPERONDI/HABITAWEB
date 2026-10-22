<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Assinatura pelo site é "pay to use" (decisão do cliente): a primeira
 * mensalidade é cobrada na hora da compra e o painel só libera depois do
 * pagamento confirmado. A carência de plano (`plans.carencia_dias`, 3 dias por
 * padrão) fazia o contrário — vencimento para daqui a 3 dias e painel liberado
 * de imediato (PaymentService::determineInitialSubscriptionStatus).
 *
 * Zera a carência de todos os planos e o default da coluna. Condição especial
 * para quem negociou é a carência negociada do superadmin, não esta.
 */
class PayToUsePlans extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE plans ALTER COLUMN carencia_dias SET DEFAULT 0');
        $this->db->table('plans')->where('carencia_dias >', 0)->update(['carencia_dias' => 0]);
    }

    public function down()
    {
        $this->db->query('ALTER TABLE plans ALTER COLUMN carencia_dias SET DEFAULT 3');
        // Os valores anteriores de cada plano não são restaurados: não há como
        // saber qual plano tinha qual carência antes do up().
    }
}
