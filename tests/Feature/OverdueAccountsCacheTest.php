<?php

namespace Tests\Feature;

use App\Models\PaymentTransactionModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre o contrato de cache de PaymentTransactionModel::getOverdueAccountIdsCached():
 * (1) lista vazia também é cacheada (o caso comum "ninguém inadimplente" é o que
 * mais precisa de cache — [] !== null), (2) resultado não-vazio vem do cache na
 * segunda chamada, (3) upsertTransaction() invalida o cache — sem isso, uma conta
 * que quitou a fatura continuaria bloqueada nas buscas públicas por até 120s a
 * mais do que o devido (e vice-versa: recém-inadimplente continuaria visível).
 */
final class OverdueAccountsCacheTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        cache()->delete('overdue_account_ids_3');
    }

    protected function tearDown(): void
    {
        cache()->delete('overdue_account_ids_3');
        parent::tearDown();
    }

    public function testEmptyListIsCachedAndReused(): void
    {
        $model = new PaymentTransactionModel();

        $this->assertSame([], $model->getOverdueAccountIdsCached(3));

        // Semeia o cache manualmente com um sentinela: se a 2ª chamada fosse ao
        // banco, retornaria [] — receber o sentinela prova que veio do cache.
        cache()->save('overdue_account_ids_3', [999888], 120);
        $this->assertSame([999888], $model->getOverdueAccountIdsCached(3));
    }

    public function testOverdueAccountAppearsAndUpsertInvalidatesCache(): void
    {
        $tenant = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;

        $model = new PaymentTransactionModel();

        // Aquece o cache ANTES da fatura vencida existir → lista vazia cacheada.
        $this->assertSame([], $model->getOverdueAccountIdsCached(3));

        // upsertTransaction (o choke point de todo webhook de gateway) cria a
        // fatura vencida E deve invalidar o cache no mesmo ato.
        $result = $model->upsertTransaction([
            'account_id'             => $accountId,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => 'e2e_overdue_' . uniqid(),
            'amount'                 => 100.00,
            'status'                 => 'OVERDUE',
            'due_date'               => date('Y-m-d', strtotime('-10 days')),
        ]);

        $this->assertNotFalse($result, 'upsertTransaction falhou: ' . json_encode($model->errors()));
        $this->assertContains($accountId, $model->getOverdueAccountIdsCached(3));
    }
}
