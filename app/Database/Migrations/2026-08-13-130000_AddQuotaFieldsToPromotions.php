<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * `promotions` ganha o que falta para virar a cota mensal de turbinadas.
 *
 * Hoje `promotions` só sabe QUAL imóvel e QUANDO — não sabe DE QUEM (é preciso
 * fazer join com `properties`, que quebra se o imóvel mudar de dono ou for
 * apagado), nem SE FOI PAGO ou concedido pelo plano, nem A QUE MÊS pertence.
 * Sem isso não há como contar "quantas turbinadas esta conta já usou este mês".
 *
 * - `account_id` — denormalizado de propósito. Contar cota via join com
 *   `properties` é frágil: o vínculo se perde se o imóvel for excluído, e o
 *   histórico mudaria retroativamente se o imóvel trocasse de conta.
 * - `origem` — PLANO (consumiu a cota mensal, custo zero), PAGO (pacote
 *   avulso via Asaas) ou CORTESIA (concedida manualmente). É o que distingue
 *   "gastou a cota" de "comprou à parte" na contagem.
 * - `periodo` — primeiro dia do mês de competência. A cota é por
 *   `(account_id, origem='PLANO', periodo)`; trocar de mês é só isso mudar de
 *   valor, sem cron de reset e sem risco de dupla concessão.
 * - `payment_transaction_id` + índice UNIQUE parcial — é o que torna o
 *   webhook de confirmação de pagamento IDEMPOTENTE: um replay do Asaas não
 *   pode criar uma segunda promoção para a mesma cobrança.
 * - `promotion_package_id` — qual pacote originou (auditoria/relatório).
 */
class AddQuotaFieldsToPromotions extends Migration
{
    public function up()
    {
        $this->db->resetDataCache();

        $fields = [];

        if (! $this->db->fieldExists('account_id', 'promotions')) {
            $fields['account_id'] = [
                'type'       => 'BIGINT',
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'property_id',
            ];
        }

        if (! $this->db->fieldExists('origem', 'promotions')) {
            $fields['origem'] = [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => false,
                'default'    => 'PAGO',
                'after'      => 'tipo_promocao',
            ];
        }

        if (! $this->db->fieldExists('periodo', 'promotions')) {
            $fields['periodo'] = [
                'type' => 'DATE',
                'null' => true,
                'after' => 'origem',
            ];
        }

        if (! $this->db->fieldExists('payment_transaction_id', 'promotions')) {
            $fields['payment_transaction_id'] = [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ];
        }

        if (! $this->db->fieldExists('promotion_package_id', 'promotions')) {
            $fields['promotion_package_id'] = [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('promotions', $fields);
        }

        // Backfill: origem/periodo/account_id das linhas já existentes.
        // origem fica PAGO por default (é o que o fluxo de sempre produzia) e
        // periodo vira o mês de data_inicio de cada linha.
        $this->db->query(
            "UPDATE promotions p
                SET account_id = (SELECT account_id FROM properties WHERE properties.id = p.property_id),
                    periodo    = date_trunc('month', p.data_inicio)::date
              WHERE p.account_id IS NULL"
        );

        if (! $this->indexExists('idx_promotions_quota')) {
            $this->forge->addKey(['account_id', 'origem', 'periodo'], false, false, 'idx_promotions_quota');
            $this->forge->processIndexes('promotions');
        }

        // UNIQUE parcial: só entre linhas com payment_transaction_id preenchido.
        // Um índice UNIQUE normal rejeitaria múltiplos NULL só em bancos que não
        // tratam NULL como distinto — Postgres já trata NULL <> NULL, então um
        // índice comum já funcionaria, mas o parcial deixa a intenção explícita
        // e não indexa as linhas de cota/cortesia à toa.
        $this->db->query(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_promotions_payment_transaction
                ON promotions (payment_transaction_id)
                WHERE payment_transaction_id IS NOT NULL'
        );
    }

    public function down()
    {
        $this->db->query('DROP INDEX IF EXISTS uq_promotions_payment_transaction');

        if ($this->indexExists('idx_promotions_quota')) {
            $this->forge->dropKey('promotions', 'idx_promotions_quota');
        }

        $this->db->resetDataCache();

        foreach (['account_id', 'origem', 'periodo', 'payment_transaction_id', 'promotion_package_id'] as $column) {
            if ($this->db->fieldExists($column, 'promotions')) {
                $this->forge->dropColumn('promotions', $column);
            }
        }
    }

    private function indexExists(string $name): bool
    {
        $row = $this->db->query(
            'SELECT 1 FROM pg_indexes WHERE indexname = ?',
            [$name]
        )->getRow();

        return $row !== null;
    }
}
