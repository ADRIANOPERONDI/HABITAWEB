<?php

namespace Tests\Feature\Integrations;

use App\Database\Seeds\PlanSeeder;
use App\Entities\AccountIntegration;
use App\Libraries\Integrations\Dto\CatalogItem;
use App\Libraries\Integrations\Dto\ExternalImage;
use App\Libraries\Integrations\Dto\ExternalProperty;
use App\Libraries\Integrations\Dto\SyncCursor;
use App\Libraries\Integrations\Dto\TestResult;
use App\Libraries\Integrations\Exceptions\AuthException;
use App\Libraries\Integrations\IntegrationProviderInterface;
use App\Models\AccountIntegrationModel;
use App\Models\IntegrationSyncRunModel;
use App\Models\PropertyExternalRefModel;
use App\Models\PropertyModel;
use App\Services\IntegrationService;
use App\Services\IntegrationSyncService;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\Integrations\FakeConnector;
use Tests\Support\Integrations\FakeIntegrationService;
use Tests\Support\HabitawebTestCase;

/**
 * Sincronização de catálogo, do conector até a linha em `properties`.
 *
 * O conector é substituído por uma dublê roteirizada — nada de rede — mas todo
 * o resto é real: PropertyService, validação, banco, refs externas.
 *
 * @internal
 */
final class IntegrationSyncTest extends HabitawebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        // Trava de rodada simultânea vive no cache: sem limpar, o segundo teste
        // do arquivo acharia que já há um sync em andamento.
        cache()->clean();
    }

    /** @param list<ExternalProperty|null> $catalogo */
    private function syncService(array $catalogo, ?\Throwable $erro = null): array
    {
        $tenant      = (new TenantFactory())->create();
        $service     = new IntegrationService();
        $integration = $service->findOrCreate((int) $tenant['account']->id, 'simob');

        $service->saveCredentials($integration, ['base_url' => 'https://203.0.113.10', 'token' => 'x']);
        $service->saveSettings($integration, [
            'finalidades'    => [1, 2],
            'initial_status' => 'ACTIVE',
            'import_images'  => false,
        ]);

        $connector = new FakeConnector($catalogo, $erro);

        $sync = new IntegrationSyncService(new FakeIntegrationService($connector));

        return [$sync, $service->find((int) $tenant['account']->id, 'simob'), $tenant, $connector];
    }

    private function property(string $id, array $overrides = [], array $images = [], ?string $updatedAt = '2026-08-01 10:00:00'): ExternalProperty
    {
        return new ExternalProperty(
            externalId: $id,
            fields: array_merge([
                'titulo'       => "Casa {$id}",
                'tipo_negocio' => 'VENDA',
                'tipo_imovel'  => 'CASA',
                'preco'        => 350000,
                'cidade'       => 'Chapecó',
                'bairro'       => 'Centro',
                'estado'       => 'SC',
                'status'       => 'ACTIVE',
            ], $overrides),
            images: $images,
            externalCode: 'C' . $id,
            externalUpdatedAt: $updatedAt,
        );
    }

    // ------------------------------------------------------------- criação

    public function testCriaOsImoveisDoCatalogoEGravaOVinculo(): void
    {
        [$sync, $integration, $tenant] = $this->syncService([
            $this->property('100'),
            $this->property('101'),
        ]);

        $result = $sync->run($integration);

        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->errors, implode(' | ', $result->errorMessages));

        $this->seeInDatabase('properties', [
            'account_id' => $tenant['account']->id,
            'titulo'     => 'Casa 100',
            'source'     => 'integration:simob',
        ]);

        $this->seeInDatabase('property_external_refs', [
            'account_id'    => $tenant['account']->id,
            'provider_code' => 'simob',
            'external_id'   => '100',
            'external_code' => 'C100',
        ]);
    }

    public function testRegistraARodadaComOsContadores(): void
    {
        [$sync, $integration] = $this->syncService([$this->property('100')]);

        $sync->run($integration, IntegrationSyncRunModel::TRIGGER_MANUAL);

        $run = model(IntegrationSyncRunModel::class)->lastFor((int) $integration->id);

        $this->assertSame(IntegrationSyncRunModel::STATUS_SUCCESS, $run->status);
        $this->assertSame('manual', $run->trigger_type);
        $this->assertSame(1, $run->created_count);
        $this->assertNotNull($run->finished_at);
    }

    public function testMarcaOLastSyncAtParaOProximoIncremental(): void
    {
        [$sync, $integration] = $this->syncService([$this->property('100')]);

        $sync->run($integration);

        $this->assertNotNull(
            model(AccountIntegrationModel::class)->find($integration->id)->last_sync_at
        );
    }

    // ------------------------------------------------------- reprocessamento

    /**
     * O ponto que torna o sync barato: mesmo updatedAt significa que nada
     * mudou, então nem o detalhe é buscado.
     */
    public function testSegundaRodadaNaoDuplicaENemBuscaODetalheDeNovo(): void
    {
        [$sync, $integration, $tenant, $connector] = $this->syncService([$this->property('100')]);

        $sync->run($integration);
        $this->assertSame(1, $connector->resolveCalls);

        $integration = model(AccountIntegrationModel::class)->find($integration->id);
        $segundo     = $sync->run($integration);

        $this->assertSame(0, $segundo->created);
        $this->assertSame(0, $segundo->updated);
        $this->assertSame(1, $segundo->skipped);
        $this->assertSame(1, $connector->resolveCalls, 'não pode ter buscado o detalhe de novo');

        $this->assertSame(1, model(PropertyModel::class)
            ->where('account_id', $tenant['account']->id)
            ->countAllResults());
    }

    public function testUpdatedAtNovoComConteudoIgualNaoGravaDeNovo(): void
    {
        [$sync, $integration] = $this->syncService([$this->property('100')]);
        $sync->run($integration);

        // O corretor abriu e salvou sem mudar nada: a data muda, o conteúdo não.
        [$sync2, $integration2, $tenant2, $connector2] = $this->syncService([]);
        $connector2->catalogo = [$this->property('100', [], [], '2026-08-05 09:00:00')];

        // Reaproveita a mesma integração do primeiro cenário.
        $integration = model(AccountIntegrationModel::class)->find($integration->id);
        $sync3       = new IntegrationSyncService(new FakeIntegrationService(
            new FakeConnector([$this->property('100', [], [], '2026-08-05 09:00:00')])
        ));

        $result = $sync3->run($integration);

        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->updated);
        $this->assertSame(1, $result->skipped, 'payload_hash igual, nada a gravar');
    }

    public function testConteudoAlteradoNaOrigemAtualizaOImovel(): void
    {
        [$sync, $integration, $tenant] = $this->syncService([$this->property('100')]);
        $sync->run($integration);

        $integration = model(AccountIntegrationModel::class)->find($integration->id);

        $sync2 = new IntegrationSyncService(new FakeIntegrationService(new FakeConnector([
            $this->property('100', ['preco' => 399000, 'titulo' => 'Casa 100 reformada'], [], '2026-08-05 09:00:00'),
        ])));

        $result = $sync2->run($integration);

        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->created);

        $this->seeInDatabase('properties', [
            'account_id' => $tenant['account']->id,
            'titulo'     => 'Casa 100 reformada',
        ]);
    }

    /**
     * status sai de MANAGED_FIELDS (IntegrationService) por decisão de
     * produto: a origem não tem "rascunho", quem decide isso é o tenant. A
     * criação ainda aplica initial_status (senão todo imóvel nasceria sem
     * status nenhum); a partir daí, uma troca manual sobrevive a qualquer
     * atualização de conteúdo vinda do catálogo.
     */
    public function testStatusInicialSoNaCriacaoEAlteracaoManualSobrevive(): void
    {
        $tenant      = (new TenantFactory())->create();
        $service     = new IntegrationService();
        $integration = $service->findOrCreate((int) $tenant['account']->id, 'simob');

        $service->saveCredentials($integration, ['base_url' => 'https://203.0.113.10', 'token' => 'x']);
        $service->saveSettings($integration, [
            'finalidades'    => [1, 2],
            'initial_status' => 'DRAFT',
            'import_images'  => false,
        ]);

        $sync = new IntegrationSyncService(new FakeIntegrationService(
            new FakeConnector([$this->property('100', ['status' => 'DRAFT'])])
        ));
        $sync->run($service->find((int) $tenant['account']->id, 'simob'));

        $this->seeInDatabase('properties', [
            'account_id' => $tenant['account']->id,
            'status'     => 'DRAFT',
        ]);

        $propertyModel = model(PropertyModel::class);
        $propertyId    = $propertyModel->where('account_id', $tenant['account']->id)->first()->id;
        $propertyModel->update($propertyId, ['status' => 'ACTIVE']);

        // Segunda rodada: conteúdo mudou (força update), status da origem
        // continua vindo como DRAFT — não pode voltar a sobrescrever.
        $sync2 = new IntegrationSyncService(new FakeIntegrationService(new FakeConnector([
            $this->property('100', ['status' => 'DRAFT', 'preco' => 399000], [], '2026-08-05 09:00:00'),
        ])));
        $result = $sync2->run($service->find((int) $tenant['account']->id, 'simob'));

        $this->assertSame(1, $result->updated);
        $this->seeInDatabase('properties', [
            'id'     => $propertyId,
            'status' => 'ACTIVE',
            'preco'  => 399000,
        ]);
    }

    // ----------------------------------------------------------- resiliência

    /**
     * Um imóvel com dado sujo não pode derrubar o catálogo inteiro.
     */
    public function testItemInvalidoNaoImpedeOsDemais(): void
    {
        [$sync, $integration, $tenant] = $this->syncService([
            $this->property('100'),
            // Sem cidade nem bairro: validatePropertyData rejeita.
            $this->property('101', ['cidade' => '', 'bairro' => '']),
            $this->property('102'),
        ]);

        $result = $sync->run($integration);

        $this->assertSame(2, $result->created);
        $this->assertSame(1, $result->errors);
        $this->assertSame(IntegrationSyncRunModel::STATUS_PARTIAL, $result->status());

        $this->assertSame(2, model(PropertyModel::class)
            ->where('account_id', $tenant['account']->id)
            ->countAllResults());
    }

    /**
     * Credencial recusada desliga o sync: insistir a cada 30 min com token
     * inválido só empilha erro e pode virar bloqueio do lado de lá.
     */
    public function testCredencialRecusadaDesligaASincronizacao(): void
    {
        [$sync, $integration] = $this->syncService([], new AuthException('Credencial recusada pela plataforma externa.'));

        model(AccountIntegrationModel::class)->update($integration->id, ['is_active' => true]);
        $integration = model(AccountIntegrationModel::class)->find($integration->id);

        $sync->run($integration);

        $reloaded = model(AccountIntegrationModel::class)->find($integration->id);

        $this->assertFalse($reloaded->is_active);
        $this->assertSame(AccountIntegrationModel::STATUS_ERROR, $reloaded->status);

        $run = model(IntegrationSyncRunModel::class)->lastFor((int) $integration->id);
        $this->assertSame(IntegrationSyncRunModel::STATUS_ERROR, $run->status);
    }

    /** Item que o conector não conseguiu montar não vira imóvel quebrado. */
    public function testItemQueNaoResolveEIgnoradoNaCriacao(): void
    {
        [$sync, $integration, $tenant] = $this->syncService([null, $this->property('101')]);

        $result = $sync->run($integration);

        $this->assertSame(1, $result->created);
        $this->assertSame(1, model(PropertyModel::class)
            ->where('account_id', $tenant['account']->id)
            ->countAllResults());
    }

    /**
     * Categoria sem de/para confirmado conta como "ignorado" — não é erro
     * (não devia virar PARTIAL na rodada) nem imóvel pausado (nunca existiu).
     */
    public function testItemIgnoradoPorMapeamentoContaComoIgnoradoNaoComoErro(): void
    {
        [$sync, $integration, $tenant] = $this->syncService([
            ExternalProperty::ignored('102', 'categoria não mapeada: SEDE ESPORTIVA'),
        ]);

        $result = $sync->run($integration);

        $this->assertSame(1, $result->ignored);
        $this->assertSame(0, $result->errors);
        $this->assertSame(0, $result->created);
        $this->assertSame(IntegrationSyncRunModel::STATUS_SUCCESS, $result->status());
        $this->assertSame(0, model(PropertyModel::class)
            ->where('account_id', $tenant['account']->id)
            ->countAllResults());
    }

    /**
     * A chave do vínculo é o externalId da LISTAGEM (o que findRef() usou pra
     * localizar o item no início de syncItem()) — nunca o do ExternalProperty
     * resolvido, que o mapper monta preferindo o id do DETALHE. Gravar com uma
     * chave e buscar com outra faria toda rodada seguinte não achar o vínculo,
     * recriar o imóvel, e órfão o primeiro (ver SimobPropertyMapper::mapDetail).
     */
    public function testVinculoUsaOIdDaListagem(): void
    {
        $tenant      = (new TenantFactory())->create();
        $service     = new IntegrationService();
        $integration = $service->findOrCreate((int) $tenant['account']->id, 'simob');

        $service->saveCredentials($integration, ['base_url' => 'https://203.0.113.10', 'token' => 'x']);
        $service->saveSettings($integration, [
            'finalidades'    => [1, 2],
            'initial_status' => 'ACTIVE',
            'import_images'  => false,
        ]);

        $connector = new FakeConnector(
            catalogo: [$this->property('id-do-detalhe')],
            listingIdOverride: [0 => 'id-da-listagem'],
        );

        $sync = new IntegrationSyncService(new FakeIntegrationService($connector));
        $sync->run($service->find((int) $tenant['account']->id, 'simob'));

        $this->seeInDatabase('property_external_refs', [
            'account_id'  => $tenant['account']->id,
            'external_id' => 'id-da-listagem',
        ]);
        $this->dontSeeInDatabase('property_external_refs', [
            'account_id'  => $tenant['account']->id,
            'external_id' => 'id-do-detalhe',
        ]);
    }

    // ---------------------------------------------------------- desaparecidos

    /**
     * Sumir do catálogo pausa, nunca deleta: o imóvel pode ter leads e
     * histórico, e o sumiço pode ser temporário.
     */
    public function testImovelQueSumiuDoCatalogoEPausadoENaoApagado(): void
    {
        [$sync, $integration, $tenant] = $this->syncService([
            $this->property('100'),
            $this->property('101'),
        ]);

        $sync->run($integration);

        $refModel = model(PropertyExternalRefModel::class);
        $sumido   = $refModel->findRef((int) $tenant['account']->id, 'simob', '101');

        // Segunda rodada COMPLETA (last_sync_at zerado) sem o imóvel 101.
        model(AccountIntegrationModel::class)->update($integration->id, ['last_sync_at' => null]);
        $integration = model(AccountIntegrationModel::class)->find($integration->id);

        $sync2  = new IntegrationSyncService(new FakeIntegrationService(new FakeConnector([
            $this->property('100', [], [], '2026-08-09 10:00:00'),
        ])));
        $result = $sync2->run($integration);

        $this->assertSame(1, $result->paused);

        $property = model(PropertyModel::class)->find($sumido->property_id);
        $this->assertNotNull($property, 'não pode ter sido apagado');
        $this->assertSame('PAUSED', $property->status);
    }

    /**
     * Numa rodada INCREMENTAL o catálogo devolvido é só o que mudou. Pausar
     * quem não apareceu derrubaria o catálogo inteiro do tenant.
     */
    public function testSyncIncrementalNaoPausaQuemNaoVeioNaRodada(): void
    {
        [$sync, $integration, $tenant] = $this->syncService([
            $this->property('100'),
            $this->property('101'),
        ]);

        $sync->run($integration);

        // last_sync_at preenchido = incremental.
        $integration = model(AccountIntegrationModel::class)->find($integration->id);
        $this->assertNotNull($integration->last_sync_at);

        $sync2  = new IntegrationSyncService(new FakeIntegrationService(new FakeConnector([
            $this->property('100', ['preco' => 400000], [], '2026-08-09 10:00:00'),
        ])));
        $result = $sync2->run($integration);

        $this->assertSame(0, $result->paused, 'incremental não pode pausar');

        $ativos = model(PropertyModel::class)
            ->where('account_id', $tenant['account']->id)
            ->where('status', 'ACTIVE')
            ->countAllResults();

        $this->assertSame(2, $ativos);
    }

    // -------------------------------------------------------------- imagens

    public function testImagensSaoIngeridasQuandoOTenantPede(): void
    {
        $tenant      = (new TenantFactory())->create();
        $service     = new IntegrationService();
        $integration = $service->findOrCreate((int) $tenant['account']->id, 'simob');
        $service->saveCredentials($integration, ['base_url' => 'https://203.0.113.10', 'token' => 'x']);
        $service->saveSettings($integration, ['finalidades' => [2], 'initial_status' => 'ACTIVE', 'import_images' => true]);

        $catalogo = [$this->property('100', [], [
            new ExternalImage('https://203.0.113.10/cdn/imovelImages/100/a.jpg', 1, true),
        ])];

        $propertyService = new class () extends \App\Services\PropertyService {
            public array $urls = [];

            public function addMediaFromUrl(string $url, int $propertyId, array $options = []): array
            {
                $this->urls[] = $url;

                return ['success' => true, 'skipped' => false];
            }
        };

        $sync = new IntegrationSyncService(
            new FakeIntegrationService(new FakeConnector($catalogo)),
            $propertyService
        );

        $result = $sync->run($service->find((int) $tenant['account']->id, 'simob'));

        $this->assertSame(1, $result->images);
        $this->assertSame(['https://203.0.113.10/cdn/imovelImages/100/a.jpg'], $propertyService->urls);
    }

    // ---------------------------------------------------------------- trava

    public function testNaoRodaDuasVezesEmParalelo(): void
    {
        [$sync, $integration] = $this->syncService([$this->property('100')]);

        cache()->save('integration_sync_lock_' . $integration->id, time(), 60);

        $result = $sync->run($integration);

        $this->assertSame(0, $result->created);
        $this->assertStringContainsString('em andamento', $result->errorSummary());
    }

    // -------------------------------------------------- prioridade do botão

    /**
     * "Sincronizar agora" só marca sync_priority_requested_at
     * (IntegrationService::markPriority()) — quem consome é esta mesma
     * chamada a run(), e o campo precisa sair limpo, senão a integração
     * fica "prioritária" pra sempre em AccountIntegrationModel::dueForSync().
     */
    public function testPedidoDePrioridadeELimpoAoConcluirComSucesso(): void
    {
        [$sync, $integration] = $this->syncService([$this->property('100')]);

        model(AccountIntegrationModel::class)->update($integration->id, [
            'sync_priority_requested_at' => date('Y-m-d H:i:s'),
        ]);
        $integration = model(AccountIntegrationModel::class)->find($integration->id);
        $this->assertNotNull($integration->sync_priority_requested_at);

        $sync->run($integration);

        $this->assertNull(
            model(AccountIntegrationModel::class)->find($integration->id)->sync_priority_requested_at
        );
    }

    /** Mesmo numa rodada que termina em erro, o pedido não pode ficar preso. */
    public function testPedidoDePrioridadeELimpoMesmoComErro(): void
    {
        [$sync, $integration] = $this->syncService([], new AuthException('Credencial recusada pela plataforma externa.'));

        model(AccountIntegrationModel::class)->update($integration->id, [
            'is_active'                  => true,
            'sync_priority_requested_at' => date('Y-m-d H:i:s'),
        ]);
        $integration = model(AccountIntegrationModel::class)->find($integration->id);

        $sync->run($integration);

        $this->assertNull(
            model(AccountIntegrationModel::class)->find($integration->id)->sync_priority_requested_at
        );
    }
}
