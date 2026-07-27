<?php

namespace Tests\Feature\Api;

use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\ApiTestTrait;
use Tests\Support\HabitawebTestCase;

/**
 * Fecha a via de mão dupla: o parceiro empurra o catálogo, puxa de volta o que
 * mudou no Habitaweb e reimporta — sem duplicar nada em nenhuma direção.
 *
 * @internal
 */
final class ExportRoundTripTest extends HabitawebTestCase
{
    use ApiTestTrait;
    use DatabaseTestTrait;

    protected $seed = 'App\Database\Seeds\PlanSeeder';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetApiState();
    }

    private function catalogo(): array
    {
        return [
            [
                'external_id'  => 'SYNC-1',
                'titulo'       => 'Studio no Bom Fim',
                'descricao'    => 'Studio compacto, ideal para investidor.',
                'tipo_negocio' => 'ALUGUEL',
                'tipo_imovel'  => 'apartamento',
                'preco'        => 1800,
                'cidade'       => 'Porto Alegre',
                'bairro'       => 'Bom Fim',
                'estado'       => 'RS',
            ],
            [
                'external_id'  => 'SYNC-2',
                'titulo'       => 'Casa no Tristeza',
                'descricao'    => 'Casa com pátio e churrasqueira.',
                'tipo_negocio' => 'VENDA',
                'tipo_imovel'  => 'casa',
                'preco'        => 890000,
                'cidade'       => 'Porto Alegre',
                'bairro'       => 'Tristeza',
                'estado'       => 'RS',
            ],
        ];
    }

    public function testExportReturnsExternalIdForReconciliation(): void
    {
        $tenant = $this->makeApiTenant();

        $this->postJson('api/v1/import/properties', ['properties' => $this->catalogo()], $tenant['api_key'])
            ->assertStatus(200);

        $result = $this->withHeaders($this->withApiKey($tenant['api_key']))
            ->get('api/v1/export/properties?format=json');

        $result->assertStatus(200);
        $data = $this->envelope($result)['data'];

        $this->assertSame(2, $data['pagination']['total']);

        $externalIds = array_column($data['properties'], 'external_id');
        sort($externalIds);
        $this->assertSame(['SYNC-1', 'SYNC-2'], $externalIds);

        // Cada item traz o suficiente para reconciliar do lado do parceiro.
        foreach ($data['properties'] as $property) {
            $this->assertArrayHasKey('property_id', $property);
            $this->assertArrayHasKey('updated_at', $property);
            $this->assertArrayHasKey('images', $property);
        }
    }

    public function testRoundTripExportThenReimportDoesNotDuplicate(): void
    {
        $tenant = $this->makeApiTenant();

        $this->postJson('api/v1/import/properties', ['properties' => $this->catalogo()], $tenant['api_key'])
            ->assertStatus(200);

        // O parceiro puxa o estado atual do Habitaweb...
        $export = $this->withHeaders($this->withApiKey($tenant['api_key']))
            ->get('api/v1/export/properties?format=json');
        $export->assertStatus(200);

        $exported = $this->envelope($export)['data']['properties'];
        $this->assertCount(2, $exported);

        // ...e devolve exatamente o que recebeu.
        $reimport = $this->postJson(
            'api/v1/import/properties',
            ['properties' => $exported],
            $tenant['api_key']
        );

        $reimport->assertStatus(200);
        $summary = $this->envelope($reimport)['data']['summary'];

        $this->assertSame(2, $summary['updated'], 'O round-trip deve atualizar, não recriar.');
        $this->assertSame(0, $summary['created']);
        $this->assertSame(0, $summary['errors']);

        $this->assertSame(
            2,
            model('App\Models\PropertyModel')->where('account_id', $tenant['account']->id)->countAllResults(),
            'O round-trip duplicou o catálogo.'
        );
    }

    public function testUpdatedSinceReturnsOnlyChangedProperties(): void
    {
        $tenant = $this->makeApiTenant();

        $this->postJson('api/v1/import/properties', ['properties' => $this->catalogo()], $tenant['api_key'])
            ->assertStatus(200);

        // Marca um corte no tempo e altera apenas um imóvel depois dele.
        $cutoff = date('c', time() + 1);
        sleep(2);

        $this->postJson('api/v1/import/properties', [
            'properties' => [[
                'external_id'  => 'SYNC-2',
                'titulo'       => 'Casa no Tristeza - preço novo',
                'tipo_negocio' => 'VENDA',
                'tipo_imovel'  => 'casa',
                'preco'        => 850000,
                'cidade'       => 'Porto Alegre',
                'bairro'       => 'Tristeza',
            ]],
        ], $tenant['api_key'])->assertStatus(200);

        $result = $this->withHeaders($this->withApiKey($tenant['api_key']))
            ->get('api/v1/export/properties?format=json&updated_since=' . urlencode($cutoff));

        $result->assertStatus(200);
        $properties = $this->envelope($result)['data']['properties'];

        $this->assertCount(1, $properties, 'updated_since deve trazer só o que mudou.');
        $this->assertSame('SYNC-2', $properties[0]['external_id']);
    }

    public function testExportPaginationWorks(): void
    {
        $tenant = $this->makeApiTenant();

        $this->postJson('api/v1/import/properties', ['properties' => $this->catalogo()], $tenant['api_key'])
            ->assertStatus(200);

        $page1 = $this->withHeaders($this->withApiKey($tenant['api_key']))
            ->get('api/v1/export/properties?format=json&per_page=1&page=1');
        $page1->assertStatus(200);
        $d1 = $this->envelope($page1)['data'];

        $page2 = $this->withHeaders($this->withApiKey($tenant['api_key']))
            ->get('api/v1/export/properties?format=json&per_page=1&page=2');
        $page2->assertStatus(200);
        $d2 = $this->envelope($page2)['data'];

        $this->assertCount(1, $d1['properties']);
        $this->assertCount(1, $d2['properties']);
        $this->assertSame(2, $d1['pagination']['total']);
        $this->assertSame(2, $d1['pagination']['last_page']);
        $this->assertNotSame(
            $d1['properties'][0]['external_id'],
            $d2['properties'][0]['external_id'],
            'As páginas devem trazer registros diferentes.'
        );
    }

    public function testCsvExportIncludesExternalIdColumn(): void
    {
        $tenant = $this->makeApiTenant();

        $this->postJson('api/v1/import/properties', ['properties' => $this->catalogo()], $tenant['api_key'])
            ->assertStatus(200);

        $result = $this->withHeaders($this->withApiKey($tenant['api_key']))
            ->get('api/v1/export/properties?format=csv');

        $result->assertStatus(200);
        $csv = $result->getBody();

        $this->assertStringContainsString('external_id', $csv);
        $this->assertStringContainsString('SYNC-1', $csv);
    }

    public function testInvalidFormatIsRejected(): void
    {
        $tenant = $this->makeApiTenant();

        $this->withHeaders($this->withApiKey($tenant['api_key']))
            ->get('api/v1/export/properties?format=exe')
            ->assertStatus(422);
    }

    public function testExportRequiresAuthentication(): void
    {
        $this->get('api/v1/export/properties?format=json')->assertStatus(401);
    }
}
