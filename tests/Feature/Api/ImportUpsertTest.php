<?php

namespace Tests\Feature\Api;

use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\ApiTestTrait;
use Tests\Support\HabitawebTestCase;

/**
 * A "via de mão dupla": o parceiro empurra o catálogo já cadastrado na
 * plataforma dele e reenvia quando algo muda, sem duplicar nada.
 *
 * O teste central aqui é testReimportUpdatesInsteadOfDuplicating — é ele que
 * prova que external_id resolveu o retrabalho.
 *
 * @internal
 */
final class ImportUpsertTest extends HabitawebTestCase
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
                'external_id'  => 'CRM-1001',
                'titulo'       => 'Apartamento 3 quartos no Centro',
                'descricao'    => 'Apartamento reformado, andar alto, vista livre.',
                'tipo_negocio' => 'VENDA',
                'tipo_imovel'  => 'apartamento',
                'preco'        => 750000,
                'cidade'       => 'Porto Alegre',
                'bairro'       => 'Centro',
                'estado'       => 'RS',
                'quartos'      => 3,
                'banheiros'    => 2,
                'vagas'        => 1,
                'area_total'   => 98.5,
            ],
            [
                'external_id'  => 'CRM-1002',
                'titulo'       => 'Casa com pátio no Bairro Ipiranga',
                'descricao'    => 'Casa térrea, pátio amplo, ótima localização.',
                'tipo_negocio' => 'VENDA',
                'tipo_imovel'  => 'casa',
                'preco'        => 1200000,
                'cidade'       => 'Porto Alegre',
                'bairro'       => 'Ipiranga',
                'estado'       => 'RS',
                'quartos'      => 4,
            ],
            [
                'external_id'  => 'CRM-1003',
                'titulo'       => 'Sala comercial para locação',
                'descricao'    => 'Sala comercial mobiliada em prédio corporativo.',
                'tipo_negocio' => 'ALUGUEL',
                'tipo_imovel'  => 'sala',
                'preco'        => 3500,
                'cidade'       => 'Porto Alegre',
                'bairro'       => 'Moinhos de Vento',
                'estado'       => 'RS',
            ],
        ];
    }

    public function testJsonImportCreatesProperties(): void
    {
        $tenant = $this->makeApiTenant();

        $result = $this->postJson(
            'api/v1/import/properties',
            ['properties' => $this->catalogo()],
            $tenant['api_key']
        );

        $result->assertStatus(200);
        $data = $this->envelope($result)['data'];

        $this->assertSame(3, $data['summary']['total']);
        $this->assertSame(3, $data['summary']['created']);
        $this->assertSame(0, $data['summary']['errors']);

        foreach ($data['results'] as $row) {
            $this->assertSame('created', $row['action']);
            $this->assertNotNull($row['property_id'], 'property_id não pode voltar null — o parceiro precisa dele para mapear.');
        }

        $this->assertDatabaseHas('properties', [
            'account_id'  => $tenant['account']->id,
            'external_id' => 'CRM-1001',
            'titulo'      => 'Apartamento 3 quartos no Centro',
        ]);
    }

    public function testReimportUpdatesInsteadOfDuplicating(): void
    {
        $tenant   = $this->makeApiTenant();
        $catalogo = $this->catalogo();

        // 1ª sincronização
        $this->postJson('api/v1/import/properties', ['properties' => $catalogo], $tenant['api_key'])
            ->assertStatus(200);

        $countAfterFirst = model('App\Models\PropertyModel')
            ->where('account_id', $tenant['account']->id)
            ->countAllResults();
        $this->assertSame(3, $countAfterFirst);

        // O parceiro baixou o preço de um imóvel e reenvia o catálogo inteiro.
        $catalogo[0]['preco']  = 699000;
        $catalogo[0]['titulo'] = 'Apartamento 3 quartos no Centro - REAJUSTADO';

        $result = $this->postJson('api/v1/import/properties', ['properties' => $catalogo], $tenant['api_key']);

        $result->assertStatus(200);
        $data = $this->envelope($result)['data'];

        $this->assertSame(3, $data['summary']['updated'], 'Reimport deve atualizar, não criar.');
        $this->assertSame(0, $data['summary']['created']);

        foreach ($data['results'] as $row) {
            $this->assertSame('updated', $row['action']);
        }

        // O ponto todo: continua com 3 imóveis, não 6.
        $countAfterSecond = model('App\Models\PropertyModel')
            ->where('account_id', $tenant['account']->id)
            ->countAllResults();
        $this->assertSame(3, $countAfterSecond, 'Reimportar não pode duplicar o catálogo.');

        $this->assertDatabaseHas('properties', [
            'external_id' => 'CRM-1001',
            'preco'       => 699000,
        ]);
    }

    public function testValidateOnlyDoesNotPersistAndDoesNotCrash(): void
    {
        // Este caminho era 500 garantido: ImportController chamava
        // PropertyService::validatePropertyData(), método que não existia.
        $tenant = $this->makeApiTenant();

        $result = $this->postJson(
            'api/v1/import/properties',
            [
                'properties'    => $this->catalogo(),
                'validate_only' => true,
            ],
            $tenant['api_key']
        );

        $result->assertStatus(200);
        $data = $this->envelope($result)['data'];

        $this->assertTrue($data['validate_only']);
        $this->assertSame(3, $data['summary']['created']);
        $this->assertSame('would_create', $data['results'][0]['action']);

        $this->assertSame(
            0,
            model('App\Models\PropertyModel')->where('account_id', $tenant['account']->id)->countAllResults(),
            'validate_only não pode gravar nada.'
        );
    }

    public function testInvalidItemsReportPerItemErrorsWithoutBlockingValidOnes(): void
    {
        $tenant = $this->makeApiTenant();

        $result = $this->postJson(
            'api/v1/import/properties',
            [
                'properties' => [
                    $this->catalogo()[0],
                    ['external_id' => 'CRM-BAD', 'titulo' => 'Sem preço nem cidade'],
                ],
            ],
            $tenant['api_key']
        );

        // 207 Multi-Status: sucesso parcial.
        $result->assertStatus(207);
        $data = $this->envelope($result)['data'];

        $this->assertSame(1, $data['summary']['created']);
        $this->assertSame(1, $data['summary']['errors']);

        $this->assertSame('created', $data['results'][0]['action']);
        $this->assertSame('error', $data['results'][1]['action']);
        $this->assertArrayHasKey('preco', $data['results'][1]['errors']);
        $this->assertArrayHasKey('cidade', $data['results'][1]['errors']);

        // O item válido foi mesmo gravado.
        $this->assertDatabaseHas('properties', ['external_id' => 'CRM-1001']);
        $this->assertDatabaseMissing('properties', ['external_id' => 'CRM-BAD']);
    }

    public function testFieldAliasesFromPartnerSchemaAreAccepted(): void
    {
        $tenant = $this->makeApiTenant();

        $result = $this->postJson('api/v1/import/properties', [
                'properties' => [[
                    'reference'    => 'EXT-9',
                    'title'        => 'Cobertura duplex frente mar',
                    'description'  => 'Cobertura com vista panorâmica para o mar.',
                    'operation'    => 'sale',
                    'property_type' => 'cobertura',
                    'price'        => 2500000,
                    'city'         => 'Florianópolis',
                    'neighborhood' => 'Jurerê',
                    'state'        => 'sc',
                    'bedrooms'     => 4,
                ]],
            ], $tenant['api_key']);

        $result->assertStatus(200);
        $this->assertSame(1, $this->envelope($result)['data']['summary']['created']);

        $this->assertDatabaseHas('properties', [
            'external_id'  => 'EXT-9',
            'titulo'       => 'Cobertura duplex frente mar',
            'tipo_negocio' => 'VENDA',
            'estado'       => 'SC',
            'quartos'      => 4,
        ]);
    }

    public function testImportCannotWriteAnotherAccountId(): void
    {
        $victim  = $this->makeApiTenant();
        $partner = $this->makeApiTenant();

        $item                = $this->catalogo()[0];
        $item['account_id']  = $victim['account']->id;

        $this->postJson('api/v1/import/properties', ['properties' => [$item]], $partner['api_key'])
            ->assertStatus(200);

        // Foi para a conta do parceiro autenticado, não para a vítima.
        $this->assertDatabaseHas('properties', [
            'external_id' => 'CRM-1001',
            'account_id'  => $partner['account']->id,
        ]);
        $this->assertDatabaseMissing('properties', [
            'external_id' => 'CRM-1001',
            'account_id'  => $victim['account']->id,
        ]);
    }

    public function testGuardedFieldsAreStrippedOnImport(): void
    {
        $tenant = $this->makeApiTenant();

        $item                       = $this->catalogo()[0];
        $item['is_destaque']        = true;
        $item['highlight_level']    = 3;
        $item['is_verified']        = true;
        $item['verification_status'] = 'APPROVED';

        $this->postJson('api/v1/import/properties', ['properties' => [$item]], $tenant['api_key'])
            ->assertStatus(200);

        $property = model('App\Models\PropertyModel')
            ->where('external_id', 'CRM-1001')
            ->first();

        $this->assertFalse((bool) $property->is_destaque, 'Turbo não pode ser concedido pelo payload.');
        $this->assertSame(0, (int) $property->highlight_level);
        $this->assertFalse((bool) $property->is_verified, 'Selo verificado não pode ser concedido pelo payload.');
    }

    public function testEmptyPayloadIsRejectedWith400(): void
    {
        $tenant = $this->makeApiTenant();

        $result = $this->postJson('api/v1/import/properties', ['properties' => []], $tenant['api_key']);

        $result->assertStatus(400);
        $this->assertSame('INVALID_PAYLOAD', $this->envelope($result)['error_code']);
    }

    public function testBatchLimitIsEnforced(): void
    {
        $tenant = $this->makeApiTenant();
        $items  = array_fill(0, 201, $this->catalogo()[0]);

        $result = $this->postJson('api/v1/import/properties', ['properties' => $items], $tenant['api_key']);

        $result->assertStatus(422);
    }

    public function testImportRequiresAuthentication(): void
    {
        $this->postJson('api/v1/import/properties', ['properties' => $this->catalogo()])
            ->assertStatus(401);
    }
}
