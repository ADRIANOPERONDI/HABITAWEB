<?php

namespace Tests\Feature\Api;

use App\Services\PropertyService;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\ApiTestTrait;
use Tests\Support\HabitawebTestCase;

/**
 * Isolamento entre contas (multi-tenancy) em toda a superfície da API.
 *
 * Regressão dos dois vazamentos mais graves da auditoria:
 *  - GET /properties devolvia os imóveis ATIVOS de TODAS as contas, porque o
 *    controller repassava o querystring cru e PropertyService::listProperties()
 *    só filtra por conta quando o chamador manda account_id;
 *  - GET /accounts dumpava a tabela inteira (nome, e-mail, telefone, CPF/CNPJ),
 *    porque AccountService::listAccounts() ignorava em silêncio as chaves
 *    'id'/'parent_id' que o controller montava.
 *
 * @internal
 */
final class TenantIsolationTest extends HabitawebTestCase
{
    use ApiTestTrait;
    use DatabaseTestTrait;

    protected $seed = 'App\Database\Seeds\PlanSeeder';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetApiState();
    }

    private function makeProperty(array $tenant, string $titulo, string $status = 'ACTIVE'): int
    {
        $result = (new PropertyService())->trySaveProperty([
            'account_id'   => $tenant['account']->id,
            'titulo'       => $titulo,
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'apartamento',
            'preco'        => 400000,
            'cidade'       => 'Porto Alegre',
            'bairro'       => 'Centro',
            'status'       => $status,
        ]);

        $this->assertTrue($result['success'], 'Setup falhou: ' . ($result['message'] ?? ''));

        return (int) $result['property_id'];
    }

    public function testPropertyListingNeverLeaksAnotherTenant(): void
    {
        $alice = $this->makeApiTenant(['nome' => 'Imobiliária Alice']);
        $bob   = $this->makeApiTenant(['nome' => 'Imobiliária Bob']);

        $this->makeProperty($alice, 'Apartamento da Alice');
        $this->makeProperty($bob, 'Cobertura do Bob');

        $result = $this->withHeaders($this->withApiKey($alice['api_key']))->get('api/v1/properties');
        $result->assertStatus(200);

        $body = $result->getJSON();

        $this->assertStringContainsString('Apartamento da Alice', $body);
        $this->assertStringNotContainsString(
            'Cobertura do Bob',
            $body,
            'GET /properties vazou imóvel de outra conta.'
        );

        foreach ($this->envelope($result)['data']['properties'] as $property) {
            $this->assertSame(
                (int) $alice['account']->id,
                (int) $property['account_id'],
                'Apareceu imóvel de outra conta na listagem.'
            );
        }
    }

    public function testAccountIdInQueryStringCannotWidenScope(): void
    {
        $alice = $this->makeApiTenant();
        $bob   = $this->makeApiTenant();

        $this->makeProperty($bob, 'Cobertura do Bob');

        // Tentativa explícita de espiar a conta alheia pelo querystring.
        $result = $this->withHeaders($this->withApiKey($alice['api_key']))
            ->get('api/v1/properties?account_id=' . $bob['account']->id);

        $result->assertStatus(200);
        $this->assertStringNotContainsString('Cobertura do Bob', $result->getJSON());
    }

    public function testStatusAllCannotRevealOtherTenantsDrafts(): void
    {
        $alice = $this->makeApiTenant();
        $bob   = $this->makeApiTenant();

        $this->makeProperty($bob, 'Rascunho secreto do Bob', 'DRAFT');

        $result = $this->withHeaders($this->withApiKey($alice['api_key']))
            ->get('api/v1/properties?status=ALL');

        $result->assertStatus(200);
        $this->assertStringNotContainsString('Rascunho secreto do Bob', $result->getJSON());
    }

    public function testAccountListingOnlyReturnsOwnAccount(): void
    {
        $alice = $this->makeApiTenant(['nome' => 'Alice Imóveis', 'tipo_conta' => 'CORRETOR']);
        $bob   = $this->makeApiTenant(['nome' => 'Bob Negócios Imobiliários']);

        $result = $this->withHeaders($this->withApiKey($alice['api_key']))->get('api/v1/accounts');
        $result->assertStatus(200);

        $accounts = $this->envelope($result)['data']['accounts'];

        $this->assertCount(1, $accounts, 'GET /accounts deve devolver só a própria conta.');
        $this->assertSame((int) $alice['account']->id, (int) $accounts[0]['id']);
        $this->assertStringNotContainsString('Bob Negócios', $result->getJSON());
    }

    public function testAccountListingDoesNotLeakOtherTenantsDocument(): void
    {
        $alice = $this->makeApiTenant();
        $bob   = $this->makeApiTenant(['documento' => '99887766554433']);

        $result = $this->withHeaders($this->withApiKey($alice['api_key']))->get('api/v1/accounts');

        $this->assertStringNotContainsString(
            '99887766554433',
            $result->getJSON(),
            'CPF/CNPJ de outra conta vazou na listagem.'
        );
    }

    public function testShowUpdateDeleteAreScopedByTenant(): void
    {
        $owner    = $this->makeApiTenant();
        $attacker = $this->makeApiTenant();
        $id       = $this->makeProperty($owner, 'Casa protegida');

        $this->withHeaders($this->withApiKey($attacker['api_key']))
            ->get("api/v1/properties/{$id}")
            ->assertStatus(403);

        $this->putJson("api/v1/properties/{$id}", ['titulo' => 'Invadido'], $attacker['api_key'])
            ->assertStatus(403);

        $this->withHeaders($this->withApiKey($attacker['api_key']))
            ->delete("api/v1/properties/{$id}")
            ->assertStatus(403);

        // Nada foi alterado.
        $this->assertDatabaseHas('properties', [
            'id'         => $id,
            'titulo'     => 'Casa protegida',
            'deleted_at' => null,
        ]);
    }

    public function testCrossTenantAccountAccessIsForbidden(): void
    {
        $alice = $this->makeApiTenant();
        $bob   = $this->makeApiTenant();

        $this->withHeaders($this->withApiKey($alice['api_key']))
            ->get('api/v1/accounts/' . $bob['account']->id)
            ->assertStatus(403);

        $this->putJson('api/v1/accounts/' . $bob['account']->id, ['nome' => 'Hackeado'], $alice['api_key'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('accounts', ['id' => $bob['account']->id, 'nome' => 'Hackeado']);
    }

    public function testLeadsAreScopedByTenant(): void
    {
        $owner    = $this->makeApiTenant();
        $attacker = $this->makeApiTenant();
        $id       = $this->makeProperty($owner, 'Casa com lead');

        $this->postJson('api/v1/leads', [
            'property_id'    => $id,
            'nome_visitante' => 'Maria Compradora',
            'email_visitante' => 'maria@example.com',
        ])->assertStatus(201);

        // O dono vê o lead.
        $ownerView = $this->withHeaders($this->withApiKey($owner['api_key']))->get('api/v1/leads');
        $ownerView->assertStatus(200);
        $this->assertStringContainsString('maria@example.com', $ownerView->getJSON());

        // O invasor não.
        $attackerView = $this->withHeaders($this->withApiKey($attacker['api_key']))->get('api/v1/leads');
        $attackerView->assertStatus(200);
        $this->assertStringNotContainsString('maria@example.com', $attackerView->getJSON());
    }

    public function testExportIsScopedByTenant(): void
    {
        $alice = $this->makeApiTenant();
        $bob   = $this->makeApiTenant();

        $this->makeProperty($alice, 'Imóvel exportável da Alice');
        $this->makeProperty($bob, 'Imóvel confidencial do Bob');

        $result = $this->withHeaders($this->withApiKey($alice['api_key']))
            ->get('api/v1/export/properties?format=json');

        $result->assertStatus(200);
        $json = $result->getJSON();

        $this->assertStringContainsString('Imóvel exportável da Alice', $json);
        $this->assertStringNotContainsString('Imóvel confidencial do Bob', $json);
    }

    public function testWebhooksAreScopedByTenant(): void
    {
        $alice = $this->makeApiTenant();
        $bob   = $this->makeApiTenant();

        $created = $this->postJson('api/v1/webhooks', [
            'name'       => 'Webhook do Bob',
            'event'      => 'lead.created',
            'target_url' => 'https://example.com/hook',
        ], $bob['api_key']);
        $created->assertStatus(201);

        $webhookId = $this->envelope($created)['data']['webhook']['id'];

        $aliceList = $this->withHeaders($this->withApiKey($alice['api_key']))->get('api/v1/webhooks');
        $aliceList->assertStatus(200);
        $this->assertStringNotContainsString('Webhook do Bob', $aliceList->getJSON());

        $this->withHeaders($this->withApiKey($alice['api_key']))
            ->get("api/v1/webhooks/{$webhookId}")
            ->assertStatus(403);
    }
}
