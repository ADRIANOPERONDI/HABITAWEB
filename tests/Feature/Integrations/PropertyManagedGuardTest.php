<?php

namespace Tests\Feature\Integrations;

use App\Database\Seeds\PlanSeeder;
use App\Models\PropertyExternalRefModel;
use App\Models\PropertyMediaModel;
use App\Models\PropertyModel;
use App\Services\IntegrationService;
use App\Services\PropertyService;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\ApiTestTrait;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Imóvel espelhado de integração é read-only nos campos que o sync
 * sobrescreve — e a guarda precisa valer em TODO caminho de escrita, não só
 * no PropertyController do admin (que era o único lugar que a aplicava).
 *
 * A API v1 do tenant reescrevia tudo sem check nenhum, e o delete de ambos
 * apagava o imóvel deixando o vínculo pra trás — o próximo sync recriava, sem
 * nenhum aviso de que a exclusão não pegou.
 *
 * @internal
 */
final class PropertyManagedGuardTest extends HabitawebTestCase
{
    use ApiTestTrait;
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->resetApiState();
    }

    /** @return array{0: array, 1: int} tenant e id de um imóvel já espelhado */
    private function tenantComImovelEspelhado(array $tenant): array
    {
        $propertyId = model(PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Casa da origem',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'CASA',
            'preco'        => 350000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);

        model(PropertyExternalRefModel::class)->insert([
            'property_id'   => $propertyId,
            'account_id'    => $tenant['account']->id,
            'provider_code' => 'simob',
            'external_id'   => '900',
        ]);

        return [$tenant, (int) $propertyId];
    }

    public function testUpdateViaApiIgnoraCamposGerenciados(): void
    {
        [$tenant, $propertyId] = $this->tenantComImovelEspelhado($this->makeApiTenant());

        $resposta = $this->putJson(
            "api/v1/properties/{$propertyId}",
            ['preco' => 999000, 'titulo' => 'Título que o tenant tentou mudar'],
            $tenant['api_key']
        );

        $resposta->assertStatus(200);
        $body = json_decode((string) $resposta->getJSON(), true);

        $this->assertContains('preco', $body['data']['ignored_fields']);
        $this->assertContains('titulo', $body['data']['ignored_fields']);

        // A origem continua sendo a fonte da verdade.
        $property = model(PropertyModel::class)->find($propertyId);
        $this->assertSame(350000.0, (float) $property->preco);
        $this->assertSame('Casa da origem', $property->titulo);
    }

    /**
     * Campo que a origem NÃO fornece continua editável — a guarda é sobre os
     * campos gerenciados, não sobre o imóvel inteiro.
     */
    public function testCampoNaoGerenciadoContinuaEditavelViaApi(): void
    {
        [$tenant, $propertyId] = $this->tenantComImovelEspelhado($this->makeApiTenant());

        $this->putJson(
            "api/v1/properties/{$propertyId}",
            ['meta_title' => 'Casa no Centro | Imobiliária'],
            $tenant['api_key']
        )->assertStatus(200);

        $this->assertSame(
            'Casa no Centro | Imobiliária',
            model(PropertyModel::class)->find($propertyId)->meta_title
        );
    }

    public function testDeleteDeImovelEspelhadoPausaEmVezDeApagar(): void
    {
        $tenant = (new TenantFactory())->create();
        [$tenant, $propertyId] = $this->tenantComImovelEspelhado($tenant);

        $resultado = (new PropertyService())->deleteOrPauseProperty($propertyId);

        $this->assertTrue($resultado['success']);
        $this->assertTrue($resultado['paused']);

        $property = model(PropertyModel::class)->withDeleted()->find($propertyId);
        $this->assertSame('PAUSED', $property->status);
        $this->assertNull($property->deleted_at, 'espelhado não pode ser apagado, só pausado');
    }

    public function testDeleteDeImovelProprioContinuaApagando(): void
    {
        $tenant     = (new TenantFactory())->create();
        $propertyId = model(PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Imóvel do próprio tenant',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'CASA',
            'preco'        => 200000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);

        $resultado = (new PropertyService())->deleteOrPauseProperty((int) $propertyId);

        $this->assertTrue($resultado['success']);
        $this->assertFalse($resultado['paused']);
        $this->assertNotNull(
            model(PropertyModel::class)->withDeleted()->find($propertyId)->deleted_at
        );
    }

    /**
     * O dedupe de mídia consultava o model SEM withDeleted(): a foto que o
     * tenant apagou ficava invisível pra checagem, o sync achava que era
     * nova e recriava — a exclusão "não pegava".
     */
    public function testFotoApagadaPeloTenantNaoVoltaNoProximoSync(): void
    {
        $tenant = (new TenantFactory())->create();
        [$tenant, $propertyId] = $this->tenantComImovelEspelhado($tenant);

        $url        = 'https://origem.example.com/foto-1.jpg';
        $mediaModel = model(PropertyMediaModel::class);
        $mediaId    = $mediaModel->insert([
            'property_id'     => $propertyId,
            'tipo'            => 'FOTO',
            'url'             => 'uploads/properties/1/foto-1.jpg',
            'ordem'           => 1,
            'principal'       => true,
            'source_url'      => $url,
            'source_url_hash' => hash('sha256', $url),
        ], true);

        $mediaModel->delete($mediaId);

        $resultado = (new PropertyService())->addMediaFromUrl($url, $propertyId);

        $this->assertTrue($resultado['success']);
        $this->assertTrue($resultado['skipped'], 'foto apagada pelo tenant não pode ser recriada');
        $this->assertSame(0, $mediaModel->where('property_id', $propertyId)->countAllResults());
    }

    /** A guarda não pode bloquear quem ela existe para proteger: o próprio sync. */
    public function testSyncContinuaEscrevendoOsCamposGerenciados(): void
    {
        $tenant = (new TenantFactory())->create();
        [$tenant, $propertyId] = $this->tenantComImovelEspelhado($tenant);

        $resultado = (new PropertyService())->trySaveProperty(
            ['preco' => 777000, 'titulo' => 'Atualizado pela origem'],
            $propertyId,
            false,
            true,
            fromSync: true
        );

        $this->assertTrue($resultado['success'], $resultado['message'] ?? '');
        $this->assertSame([], $resultado['ignored_fields']);

        $property = model(PropertyModel::class)->find($propertyId);
        $this->assertSame(777000.0, (float) $property->preco);
        $this->assertSame('Atualizado pela origem', $property->titulo);
    }

    public function testCamposGerenciadosSaoIgnoradosSemQuebrarOSalvamento(): void
    {
        $this->assertContains('preco', IntegrationService::MANAGED_FIELDS);
        $this->assertNotContains(
            'status',
            IntegrationService::MANAGED_FIELDS,
            'status saiu da lista de propósito — quem publica o imóvel importado é o tenant'
        );
    }
}
