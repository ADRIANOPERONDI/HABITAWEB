<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Rampa de lançamento por coorte (Fase 6): mensalidade R$0 nos meses 1–6 da
 * conta, 50% nos meses 7–12, cheia a partir do 13º — contada a partir da
 * ADESÃO de cada conta, não do calendário. Por isso não é modelada como
 * `coupons` (que tem `valid_from`/`valid_until` GLOBAL): aqui a política é
 * dado (`plan_launch_ramps`), não `if` no código, e o relógio de cada conta
 * é o próprio `subscriptions.ramp_started_at`.
 *
 * `subscriptions.valor` corrige de passagem um bug preexistente: tanto
 * `SubscriptionController::upgrade()` (campo `preco_pago`) quanto
 * `PaymentService::initializeSubscription()` (campo `valor`) já tentavam
 * gravar quanto foi cobrado, mas nenhuma das duas colunas nunca existiu —
 * o valor pago não sobrevivia em lugar nenhum. Uma coluna, dois pontos de
 * escrita corrigidos.
 */
class CreatePlanLaunchRampsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'mes_de'      => ['type' => 'SMALLINT', 'comment' => 'Mes de vida da conta, inclusive, 1-indexado'],
            'mes_ate'     => ['type' => 'SMALLINT', 'null' => true, 'comment' => 'Inclusive; NULL = sem teto (ex.: 13+)'],
            'percentual'  => ['type' => 'SMALLINT', 'comment' => '0-100, percentual do preco do ciclo cobrado nessa faixa'],
            'is_active'   => ['type' => 'BOOLEAN', 'default' => true],
            'valid_from'  => ['type' => 'DATE'],
            'valid_to'    => ['type' => 'DATE', 'null' => true, 'comment' => 'NULL = rampa sem data de encerramento definida'],
            'created_at'  => ['type' => 'TIMESTAMP', 'null' => true],
            'updated_at'  => ['type' => 'TIMESTAMP', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('plan_launch_ramps');

        $this->db->table('plan_launch_ramps')->insertBatch([
            ['mes_de' => 1, 'mes_ate' => 6, 'percentual' => 0, 'is_active' => true, 'valid_from' => date('Y-m-d'), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['mes_de' => 7, 'mes_ate' => 12, 'percentual' => 50, 'is_active' => true, 'valid_from' => date('Y-m-d'), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['mes_de' => 13, 'mes_ate' => null, 'percentual' => 100, 'is_active' => true, 'valid_from' => date('Y-m-d'), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
        ]);

        $this->db->resetDataCache();

        $subscriptionColumns = [
            'ramp_started_at'    => ['type' => 'DATE', 'null' => true, 'comment' => 'Inicio do relogio da rampa desta conta. NULL = nao participa da rampa (cobra cheio).'],
            'ramp_percent_atual' => ['type' => 'SMALLINT', 'null' => true, 'comment' => 'Ultimo percentual aplicado, para auditoria de fatura.'],
            'valor'              => ['type' => 'NUMERIC', 'constraint' => '10,2', 'null' => true, 'comment' => 'Valor efetivamente cobrado (ja com desconto de rampa, se houver).'],
        ];

        $newFields = [];
        foreach ($subscriptionColumns as $name => $def) {
            if (! $this->db->fieldExists($name, 'subscriptions')) {
                $newFields[$name] = $def;
            }
        }
        if ($newFields !== []) {
            $this->forge->addColumn('subscriptions', $newFields);
        }
    }

    public function down()
    {
        $this->db->resetDataCache();

        foreach (['ramp_started_at', 'ramp_percent_atual', 'valor'] as $column) {
            if ($this->db->fieldExists($column, 'subscriptions')) {
                $this->forge->dropColumn('subscriptions', $column);
            }
        }

        $this->forge->dropTable('plan_launch_ramps', true);
    }
}
