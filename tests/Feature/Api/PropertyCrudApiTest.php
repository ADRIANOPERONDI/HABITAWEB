<?php

namespace Tests\Feature\Api;

use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\ApiTestTrait;
use Tests\Support\HabitawebTestCase;

/**
 * CRUD de imóveis pela API com payload realista de parceiro.
 *
 * @internal
 */
final class PropertyCrudApiTest extends HabitawebTestCase
{
    use ApiTestTrait;
    use DatabaseTestTrait;

    protected $seed = 'App\Database\Seeds\PlanSeeder';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetApiState();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'titulo'           => 'Apartamento 2 dormitórios com vista para o parque',
            'descricao'        => 'Apartamento semimobiliado, andar alto, sol da manhã, próximo ao parque.',
            'tipo_negocio'     => 'VENDA',
            'tipo_imovel'      => 'apartamento',
            'preco'            => 685000,
            'valor_condominio' => 780.50,
            'iptu'             => 210.00,
            'cidade'           => 'Porto Alegre',
            'bairro'           => 'Petrópolis',
            'estado'           => 'RS',
            'cep'              => '90670-000',
            'quartos'          => 2,
            'banheiros'        => 2,
            'suites'           => 1,
            'vagas'            => 1,
            'area_total'       => 78.4,
            'latitude'         => -30.0346,
            'longitude'        => -51.2177,
            'mobiliado'        => true,
            'aceita_pets'      => true,
        ], $overrides);
    }

    public function testCreateProperty(): void
    {
        $tenant = $this->makeApiTenant();

        $result = $this->postJson('api/v1/properties', $this->payload(), $tenant['api_key']);

        $result->assertStatus(201);
        $data = $this->envelope($result)['data'];

        $this->assertNotEmpty($data['property_id']);
        $this->assertDatabaseHas('properties', [
            'id'         => $data['property_id'],
            'account_id' => $tenant['account']->id,
            'titulo'     => 'Apartamento 2 dormitórios com vista para o parque',
            'cidade'     => 'Porto Alegre',
        ]);
    }

    public function testCreateRejectsMissingRequiredFieldsWith422(): void
    {
        $tenant = $this->makeApiTenant();

        // PropertyModel só validava account_id — faltando titulo/preco/cidade o
        // insert batia na constraint NOT NULL e o parceiro recebia um genérico
        // "Falha na persistência dos dados." sem saber qual campo faltou.
        $result = $this->postJson('api/v1/properties', ['tipo_imovel' => 'casa'], $tenant['api_key']);

        $result->assertStatus(422);
        $body = $this->envelope($result);

        $this->assertSame('VALIDATION_FAILED', $body['error_code']);
        foreach (['titulo', 'tipo_negocio', 'preco', 'cidade', 'bairro'] as $field) {
            $this->assertArrayHasKey($field, $body['details'], "Faltou o erro do campo {$field}.");
        }
    }

    public function testCreateRejectsInvalidEnumsAndRanges(): void
    {
        $tenant = $this->makeApiTenant();

        $result = $this->postJson('api/v1/properties', $this->payload([
            'tipo_negocio' => 'PERMUTA_LUNAR',
            'latitude'     => 999,
            'estado'       => 'RIO GRANDE DO SUL',
        ]), $tenant['api_key']);

        $result->assertStatus(422);
        $details = $this->envelope($result)['details'];

        $this->assertArrayHasKey('tipo_negocio', $details);
        $this->assertArrayHasKey('latitude', $details);
        $this->assertArrayHasKey('estado', $details);
    }

    public function testGuardedFieldsCannotBeSetOnCreate(): void
    {
        $tenant = $this->makeApiTenant();

        $result = $this->postJson('api/v1/properties', $this->payload([
            'is_destaque'     => true,
            'highlight_level' => 3,
            'is_verified'     => true,
            'score_qualidade' => 100,
        ]), $tenant['api_key']);

        $result->assertStatus(201);
        $property = model('App\Models\PropertyModel')->find($this->envelope($result)['data']['property_id']);

        $this->assertFalse((bool) $property->is_destaque, 'Turbo concedido de graça pelo payload.');
        $this->assertSame(0, (int) $property->highlight_level);
        $this->assertFalse((bool) $property->is_verified, 'Selo verificado concedido de graça pelo payload.');
    }

    public function testShowReturnsOwnProperty(): void
    {
        $tenant = $this->makeApiTenant();
        $id     = $this->envelope($this->postJson('api/v1/properties', $this->payload(), $tenant['api_key']))['data']['property_id'];

        $result = $this->withHeaders($this->withApiKey($tenant['api_key']))->get("api/v1/properties/{$id}");

        $result->assertStatus(200);
        $this->assertStringContainsString('Petrópolis', $result->getJSON());
    }

    public function testNonNumericIdReturns404NotServerError(): void
    {
        $tenant = $this->makeApiTenant();

        // getPropertyDetails(int $id) é tipado; com o placeholder (:any) do
        // resource() um id textual chegava lá e virava TypeError -> 500.
        // Agora o placeholder é (:num), então nem existe rota — o 404 override
        // de api/* responde em JSON.
        $result = $this->withHeaders($this->withApiKey($tenant['api_key']))->get('api/v1/properties/abc');

        $result->assertStatus(404);
        $this->assertSame('NOT_FOUND', $this->envelope($result)['error_code']);
    }

    public function testUnknownApiEndpointReturnsJsonNotHtml(): void
    {
        $tenant = $this->makeApiTenant();

        $result = $this->withHeaders($this->withApiKey($tenant['api_key']))
            ->get('api/v1/rota-que-nao-existe');

        $result->assertStatus(404);

        // Asserção sobre o Content-Type e o envelope, não sobre o corpo cru: no
        // PHPUnit em CLI o buffer de saída ainda embrulha a resposta no wrapper
        // de html_errors do PHP. Numa requisição HTTP real o corpo é só o JSON
        // (verificado com `curl` contra `php spark serve`).
        $result->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        $this->assertSame('NOT_FOUND', $this->envelope($result)['error_code']);
    }

    public function testPartialUpdateDoesNotWipeUnsentBooleans(): void
    {
        $tenant = $this->makeApiTenant();
        $id     = $this->envelope($this->postJson('api/v1/properties', $this->payload(), $tenant['api_key']))['data']['property_id'];

        // Só o preço. mobiliado/aceita_pets não vieram no corpo e devem
        // permanecer true — o laço de booleanos zerava tudo o que faltasse.
        $result = $this->putJson("api/v1/properties/{$id}", ['preco' => 650000], $tenant['api_key']);
        $result->assertStatus(200);

        $property = model('App\Models\PropertyModel')->find($id);

        $this->assertSame(650000.0, (float) $property->preco);
        $this->assertTrue((bool) $property->mobiliado, 'PATCH parcial apagou "mobiliado".');
        $this->assertTrue((bool) $property->aceita_pets, 'PATCH parcial apagou "aceita_pets".');
    }

    public function testUpdateCannotReassignAccount(): void
    {
        $tenant = $this->makeApiTenant();
        $other  = $this->makeApiTenant();
        $id     = $this->envelope($this->postJson('api/v1/properties', $this->payload(), $tenant['api_key']))['data']['property_id'];

        $this->putJson("api/v1/properties/{$id}", [
            'titulo'     => 'Título novo',
            'account_id' => $other['account']->id,
        ], $tenant['api_key'])->assertStatus(200);

        $this->assertDatabaseHas('properties', [
            'id'         => $id,
            'account_id' => $tenant['account']->id,
        ]);
    }

    public function testDeleteSoftDeletesProperty(): void
    {
        $tenant = $this->makeApiTenant();
        $id     = $this->envelope($this->postJson('api/v1/properties', $this->payload(), $tenant['api_key']))['data']['property_id'];

        $this->withHeaders($this->withApiKey($tenant['api_key']))
            ->delete("api/v1/properties/{$id}")
            ->assertStatus(200);

        $this->assertNull(model('App\Models\PropertyModel')->find($id));
    }

    public function testMalformedJsonBodyReturns400NotServerError(): void
    {
        $tenant = $this->makeApiTenant();

        $result = $this->withHeaders($this->jsonHeaders($tenant['api_key']))
            ->withBody('{"titulo": "sem fechar"')
            ->post('api/v1/properties');

        $result->assertStatus(400);
        $this->assertSame('INVALID_PAYLOAD', $this->envelope($result)['error_code']);
    }

    public function testFormEncodedBodyOnJsonEndpointDoesNotCrash(): void
    {
        $tenant = $this->makeApiTenant();

        // Content-Type errado era 500 (HTTPException sem código HTTP).
        $result = $this->withHeaders([
            'Authorization' => 'Bearer ' . $tenant['api_key'],
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ])->withBody('titulo=Casa&preco=1000')->post('api/v1/properties');

        $this->assertLessThan(
            500,
            $result->response()->getStatusCode(),
            'Corpo não-JSON deve virar erro de cliente, não 500.'
        );
    }

    public function testPlanLimitBlocksActivationWith409(): void
    {
        $tenant = $this->makeApiTenant();

        $subscription = model('App\Models\SubscriptionModel')->where('account_id', $tenant['account']->id)->first();
        model('App\Models\PlanModel')->update($subscription->plan_id, ['limite_imoveis_ativos' => 1]);

        $this->postJson('api/v1/properties', $this->payload(['status' => 'ACTIVE']), $tenant['api_key'])
            ->assertStatus(201);

        $result = $this->postJson(
            'api/v1/properties',
            $this->payload(['titulo' => 'Segundo imóvel ativo', 'status' => 'ACTIVE']),
            $tenant['api_key']
        );

        $result->assertStatus(409);
        $this->assertSame('PLAN_LIMIT_REACHED', $this->envelope($result)['error_code']);
    }

    public function testReportRequiresExistingPropertyAndValidType(): void
    {
        $tenant = $this->makeApiTenant();
        $id     = $this->envelope($this->postJson('api/v1/properties', $this->payload(), $tenant['api_key']))['data']['property_id'];

        // Imóvel inexistente -> 404 (antes gravava denúncia para id fantasma).
        $this->postJson('api/v1/properties/999999/report', ['reason' => 'x'], $tenant['api_key'])
            ->assertStatus(404);

        // Tipo fora da lista -> 422.
        $this->postJson("api/v1/properties/{$id}/report", [
            'reason' => 'Anúncio duplicado',
            'type'   => 'QUALQUER_COISA',
        ], $tenant['api_key'])->assertStatus(422);

        // Caso válido: user_id vem do token, não da sessão.
        $this->postJson("api/v1/properties/{$id}/report", [
            'reason' => 'Informação de área incorreta',
            'type'   => 'WRONG_INFO',
        ], $tenant['api_key'])->assertStatus(201);

        $this->assertDatabaseHas('property_reports', [
            'property_id' => $id,
            'user_id'     => $tenant['user']->id,
            'type'        => 'WRONG_INFO',
        ]);
    }
}
