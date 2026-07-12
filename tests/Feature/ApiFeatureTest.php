<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\PropertyModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre App\Filters\ApiAuth (auth por pk_ e por token Shield) e o isolamento
 * multi-tenant/IDOR em App\Controllers\Api\V1\PropertyController — o C2 corrigido
 * na auditoria. Substitui o antigo APITest (que fabricava respostas HTTP e nunca
 * exercia esse código de verdade).
 */
final class ApiFeatureTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function testMissingAuthorizationHeaderIsRejected(): void
    {
        $this->get('api/v1/properties')->assertStatus(401);
    }

    public function testInvalidApiKeyIsRejected(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer pk_test_invalidinvalidinvalidinvalid'])
            ->get('api/v1/properties')
            ->assertStatus(401);
    }

    public function testValidApiKeyReachesOwnResource(): void
    {
        $tenant = (new TenantFactory())->create();
        $apiKey = (new TenantFactory())->createApiKey($tenant['account']->id, $tenant['user']->id);

        $this->withHeaders(['Authorization' => "Bearer {$apiKey}"])
            ->get('api/v1/properties')
            ->assertOK();
    }

    /**
     * O achado C2 da auditoria: sem essa checagem, o token da conta A conseguia
     * ler/editar/apagar recursos da conta B trocando o :id na URL.
     */
    public function testCrossTenantAccessIsForbidden(): void
    {
        $factory = new TenantFactory();
        $tenantA = $factory->create();
        $tenantB = $factory->create();
        $apiKeyA = $factory->createApiKey($tenantA['account']->id, $tenantA['user']->id);

        $propertyBId = $this->insertProperty($tenantB['account']->id, ['titulo' => 'Imóvel da conta B']);

        $client = $this->withHeaders(['Authorization' => "Bearer {$apiKeyA}"]);

        $client->get("api/v1/properties/{$propertyBId}")->assertStatus(403);
        $client->put("api/v1/properties/{$propertyBId}", ['titulo' => 'Hackeado'])->assertStatus(403);
        $client->delete("api/v1/properties/{$propertyBId}")->assertStatus(403);

        // Prova de que o bloqueio é real, não só a resposta HTTP: o dado não mudou.
        $this->assertDatabaseHas('properties', [
            'id'    => $propertyBId,
            'titulo' => 'Imóvel da conta B',
        ]);
    }

    /**
     * O corpo da requisição não pode reatribuir a propriedade a outra conta —
     * o controller descarta account_id do payload antes de salvar (PropertyController::update).
     */
    public function testAccountIdInBodyCannotReassignOwnership(): void
    {
        $factory = new TenantFactory();
        $tenantA = $factory->create();
        $tenantB = $factory->create();
        $apiKeyA = $factory->createApiKey($tenantA['account']->id, $tenantA['user']->id);

        $propertyId = $this->insertProperty($tenantA['account']->id, ['titulo' => 'Imóvel original']);

        $this->withHeaders(['Authorization' => "Bearer {$apiKeyA}"])
            ->withBodyFormat('json')
            ->put("api/v1/properties/{$propertyId}", [
                'titulo'     => 'Imóvel atualizado',
                'account_id' => $tenantB['account']->id,
            ])
            ->assertOK();

        $this->assertDatabaseHas('properties', [
            'id'         => $propertyId,
            'account_id' => $tenantA['account']->id,
            'titulo'     => 'Imóvel atualizado',
        ]);
    }

    /**
     * App\Filters\ApiRateLimit: 429 após estourar rate_limit_per_hour da própria
     * chave. Usamos um limite baixo para não precisar de centenas de requisições.
     */
    public function testRateLimitReturns429AfterExceedingQuota(): void
    {
        $tenant = (new TenantFactory())->create();
        $apiKey = (new TenantFactory())->createApiKey($tenant['account']->id, $tenant['user']->id, rateLimitPerHour: 2);

        $client = $this->withHeaders(['Authorization' => "Bearer {$apiKey}"]);

        $client->get('api/v1/properties')->assertOK();
        $client->get('api/v1/properties')->assertOK();
        $client->get('api/v1/properties')->assertStatus(429);
    }

    /**
     * cidade/bairro são NOT NULL sem default na migration de properties — sem
     * preenchê-los, o INSERT falha, aborta a transação Postgres em andamento e
     * QUALQUER query seguinte na mesma requisição (mesmo de outra tabela) passa
     * a retornar false em vez de lançar (com DBDebug quebrando silenciosamente).
     */
    private function insertProperty(int $accountId, array $overrides = []): int
    {
        $propertyModel = new PropertyModel();
        $propertyModel->insert(array_merge([
            'account_id'   => $accountId,
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'apartamento',
            'titulo'       => 'Imóvel de teste',
            'cidade'       => 'São Paulo',
            'bairro'       => 'Centro',
            'preco'        => 500000,
            'status'       => 'ACTIVE',
        ], $overrides));

        return (int) $propertyModel->getInsertID();
    }
}
