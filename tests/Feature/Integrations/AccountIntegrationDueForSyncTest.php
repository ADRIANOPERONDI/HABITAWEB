<?php

namespace Tests\Feature\Integrations;

use App\Models\AccountIntegrationModel;
use App\Services\IntegrationService;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * AccountIntegrationModel::dueForSync() — quem o cron `integration:sync`
 * pega a cada rodada.
 *
 * Antes desta suíte, a query não tinha NENHUM filtro de "quão velho é
 * last_sync_at": o intervalo de sync era garantido só pela frequência do
 * cron (30 em 30 min). Isso deixou de ser seguro quando o cron passou a
 * rodar a cada minuto (pra atender o botão "sincronizar agora" com latência
 * baixa) — sem o filtro, toda integração ativa sincronizaria todo minuto.
 *
 * @internal
 */
final class AccountIntegrationDueForSyncTest extends HabitawebTestCase
{
    use DatabaseTestTrait;

    private AccountIntegrationModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = model(AccountIntegrationModel::class);
    }

    private function integration(array $overrides = []): int
    {
        $tenant      = (new TenantFactory())->create();
        $integration = (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');

        $this->model->update($integration->id, array_merge([
            'is_active' => true,
            'status'    => AccountIntegrationModel::STATUS_CONNECTED,
        ], $overrides));

        return (int) $integration->id;
    }

    public function testNuncaSincronizadaEDevida(): void
    {
        $id = $this->integration(['last_sync_at' => null]);

        $this->assertContains($id, array_map(static fn ($i) => $i->id, $this->model->dueForSync()));
    }

    public function testSincronizadaHaPoucoNaoEDevida(): void
    {
        $id = $this->integration(['last_sync_at' => date('Y-m-d H:i:s', strtotime('-5 minutes'))]);

        $this->assertNotContains($id, array_map(static fn ($i) => $i->id, $this->model->dueForSync()));
    }

    public function testSincronizadaHaMaisDeVinteECincoMinutosEDevida(): void
    {
        $id = $this->integration(['last_sync_at' => date('Y-m-d H:i:s', strtotime('-40 minutes'))]);

        $this->assertContains($id, array_map(static fn ($i) => $i->id, $this->model->dueForSync()));
    }

    /**
     * O ponto central do fix: "sincronizar agora" furou a janela de 25 min
     * mesmo numa integração que acabou de sincronizar.
     */
    public function testPrioridadePedidaEDevidaMesmoTendoSincronizadoHaPouco(): void
    {
        $id = $this->integration([
            'last_sync_at'               => date('Y-m-d H:i:s', strtotime('-1 minute')),
            'sync_priority_requested_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertContains($id, array_map(static fn ($i) => $i->id, $this->model->dueForSync()));
    }

    public function testPrioritariaVemAntesDeQuemSoEstaVencidaPorTempo(): void
    {
        $vencida     = $this->integration(['last_sync_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))]);
        $prioritaria = $this->integration([
            'last_sync_at'               => date('Y-m-d H:i:s', strtotime('-1 minute')),
            'sync_priority_requested_at' => date('Y-m-d H:i:s'),
        ]);

        $ids = array_map(static fn ($i) => $i->id, $this->model->dueForSync());

        $this->assertLessThan(
            array_search($vencida, $ids, true),
            array_search($prioritaria, $ids, true),
            'a prioritária tem que vir antes da só-vencida'
        );
    }

    public function testDesligadaNaoEDevidaMesmoVencida(): void
    {
        $id = $this->integration([
            'is_active'    => false,
            'last_sync_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
        ]);

        $this->assertNotContains($id, array_map(static fn ($i) => $i->id, $this->model->dueForSync()));
    }

    public function testComErroNaoEDevidaMesmoVencida(): void
    {
        $id = $this->integration([
            'status'       => AccountIntegrationModel::STATUS_ERROR,
            'last_sync_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
        ]);

        $this->assertNotContains($id, array_map(static fn ($i) => $i->id, $this->model->dueForSync()));
    }
}
