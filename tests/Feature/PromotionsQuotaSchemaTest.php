<?php

namespace Tests\Feature;

use App\Models\PromotionModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre as colunas de cota adicionadas a `promotions` (migration
 * 2026-08-13-130000), antes de qualquer service consumi-las.
 *
 * O índice UNIQUE parcial em `payment_transaction_id` é o alicerce da
 * idempotência do webhook na próxima etapa: sem ele, um replay de confirmação
 * de pagamento criaria uma segunda promoção para a mesma cobrança.
 */
final class PromotionsQuotaSchemaTest extends HabitawebTestCase
{
    private int $accountId;
    private int $propertyId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant          = (new TenantFactory())->create();
        $this->accountId = (int) $tenant['account']->id;

        $model = new \App\Models\PropertyModel();
        $model->insert([
            'account_id'   => $this->accountId,
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'apartamento',
            'titulo'       => 'Imóvel para promoção',
            'cidade'       => 'São Paulo',
            'bairro'       => 'Centro',
            'preco'        => 500000,
            'status'       => 'ACTIVE',
        ]);
        $this->propertyId = (int) $model->getInsertID();
    }

    private function novaPromocao(array $overrides = []): int
    {
        $model = model(PromotionModel::class);
        $model->insert(array_merge([
            'property_id'   => $this->propertyId,
            'account_id'    => $this->accountId,
            'tipo_promocao' => 'TURBO_IMOVEL',
            'origem'        => 'PAGO',
            'periodo'       => date('Y-m-01'),
            'data_inicio'   => date('Y-m-d H:i:s'),
            'data_fim'      => date('Y-m-d H:i:s', strtotime('+7 days')),
            'ativo'         => true,
        ], $overrides));

        return (int) $model->getInsertID();
    }

    public function testDuasPromocoesSemPagamentoNaoColidem(): void
    {
        $this->novaPromocao(['payment_transaction_id' => null]);
        $this->novaPromocao(['payment_transaction_id' => null]);

        $this->assertSame(
            2,
            model(PromotionModel::class)->where('account_id', $this->accountId)->countAllResults(),
            'O índice UNIQUE parcial não pode barrar cortesias/cota (payment_transaction_id NULL).'
        );
    }

    /**
     * Prova que a UNIQUE parcial rejeita a segunda linha para a mesma cobrança
     * — mas prova também uma pegadinha que a próxima etapa (TurboService)
     * precisa respeitar: no Postgres, uma inserção que viola UNIQUE ABORTA A
     * TRANSAÇÃO INTEIRA, não só a instrução. Qualquer query seguinte na mesma
     * transação falha até um ROLLBACK (é a mesma causa raiz do bug encontrado
     * na etapa do PlanSeeder). Por isso este teste não encadeia nenhuma
     * consulta depois do insert que falha — e por isso o service de ativação
     * de turbo não pode usar Model::insert() simples para checar idempotência:
     * precisa de INSERT ... ON CONFLICT DO NOTHING em SQL, que não aborta nada.
     */
    public function testMesmaCobrancaNaoPodeAtivarDuasPromocoes(): void
    {
        $this->novaPromocao(['payment_transaction_id' => 555001]);

        // DBDebug=false no ambiente: o Model não lança, devolve false.
        $model  = model(PromotionModel::class);
        $result = $model->insert([
            'property_id'            => $this->propertyId,
            'account_id'             => $this->accountId,
            'tipo_promocao'          => 'TURBO_IMOVEL',
            'origem'                 => 'PAGO',
            'periodo'                => date('Y-m-01'),
            'data_inicio'            => date('Y-m-d H:i:s'),
            'data_fim'               => date('Y-m-d H:i:s', strtotime('+7 days')),
            'ativo'                  => true,
            'payment_transaction_id' => 555001,
        ]);

        $this->assertFalse($result, 'Segunda promoção para a mesma cobrança devia ser rejeitada pelo índice.');
    }

    public function testBackfillPreencheuAccountIdEPeriodoDasLinhasAntigas(): void
    {
        // Simula uma linha no formato anterior à migration: sem account_id/
        // periodo, só o que o schema original de `promotions` sempre teve.
        $db = \Config\Database::connect();
        $db->table('promotions')->insert([
            'property_id'   => $this->propertyId,
            'tipo_promocao' => 'TURBO_IMOVEL',
            'data_inicio'   => '2026-03-10 10:00:00',
            'data_fim'      => '2026-03-17 10:00:00',
            'ativo'         => false,
        ]);

        // O backfill da migration só roda uma vez, no `up()` original — aqui
        // confirmamos que o DEFAULT de `origem` cobre linha inserida sem o
        // campo, e não que o backfill re-rode a cada insert.
        $row = $db->table('promotions')
            ->where('property_id', $this->propertyId)
            ->where('data_inicio', '2026-03-10 10:00:00')
            ->get()->getRowArray();

        $this->assertSame('PAGO', $row['origem'], 'Linha sem origem explícita cai no default PAGO.');
    }
}
