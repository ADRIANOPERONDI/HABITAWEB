<?php

namespace Tests\Feature\Api;

use App\Libraries\Auth\JwtManager;
use App\Models\ApiKeyModel;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\ApiTestTrait;
use Tests\Support\HabitawebTestCase;

/**
 * Ciclo de vida do JWT da API v1: emissão a partir da API Key, uso, rotação,
 * revogação e os modos de falha (expirado, adulterado, tipo errado).
 *
 * @internal
 */
final class AuthJwtTest extends HabitawebTestCase
{
    use ApiTestTrait;
    use DatabaseTestTrait;

    protected $seed = 'App\Database\Seeds\PlanSeeder';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetApiState();
    }

    public function testTokenEndpointExchangesApiKeyForJwtPair(): void
    {
        $tenant = $this->makeApiTenant();

        $result = $this->postJson('api/v1/auth/token', ['api_key' => $tenant['api_key']]);

        $result->assertStatus(200);
        $body = $this->envelope($result);

        $this->assertSame('Bearer', $body['data']['token_type']);
        $this->assertSame(3600, $body['data']['expires_in']);
        $this->assertSame((int) $tenant['account']->id, $body['data']['account_id']);
        $this->assertNotEmpty($body['data']['access_token']);
        $this->assertNotEmpty($body['data']['refresh_token']);

        // Access token precisa ser um JWT de 3 segmentos com os claims certos.
        $verified = (new JwtManager())->verify($body['data']['access_token']);
        $this->assertTrue($verified['valid']);
        $this->assertSame((int) $tenant['account']->id, $verified['payload']['acc']);
        $this->assertSame($tenant['key_id'], $verified['payload']['key_id']);
    }

    public function testIssuedJwtAuthenticatesProtectedEndpoint(): void
    {
        $tenant = $this->makeApiTenant();
        $jwt    = $this->tenants()->createJwt((int) $tenant['account']->id, (int) $tenant['user']->id);

        $this->withHeaders($this->withJwt($jwt['access_token']))
            ->get('api/v1/properties')
            ->assertStatus(200);
    }

    public function testTokenEndpointRejectsInvalidApiKey(): void
    {
        $result = $this->postJson('api/v1/auth/token', ['api_key' => 'pk_test_naoexiste0000000000000000000000']);

        $result->assertStatus(401);
        $this->assertSame('UNAUTHORIZED', $this->envelope($result)['error_code']);
    }

    public function testRefreshRotatesAndRevokesPreviousToken(): void
    {
        $tenant  = $this->makeApiTenant();
        $jwt     = $this->tenants()->createJwt((int) $tenant['account']->id, (int) $tenant['user']->id);
        $refresh = $jwt['refresh_token'];

        $first = $this->postJson('api/v1/auth/refresh', ['refresh_token' => $refresh]);

        $first->assertStatus(200);
        $newRefresh = $this->envelope($first)['data']['refresh_token'];
        $this->assertNotSame($refresh, $newRefresh);

        // Reuso do refresh antigo deve falhar — é a defesa contra roubo de token.
        $second = $this->postJson('api/v1/auth/refresh', ['refresh_token' => $refresh]);

        $second->assertStatus(401);
        $this->assertSame('TOKEN_REVOKED', $this->envelope($second)['error_code']);
    }

    public function testRevokeInvalidatesRefreshToken(): void
    {
        $tenant = $this->makeApiTenant();
        $jwt    = $this->tenants()->createJwt((int) $tenant['account']->id, (int) $tenant['user']->id);

        $this->postJson('api/v1/auth/revoke', ['refresh_token' => $jwt['refresh_token']])
            ->assertStatus(200);

        $this->postJson('api/v1/auth/refresh', ['refresh_token' => $jwt['refresh_token']])
            ->assertStatus(401);
    }

    public function testExpiredAccessTokenIsRejected(): void
    {
        $tenant = $this->makeApiTenant();

        // Forja um token com exp no passado. Não dá para usar JwtManager aqui
        // porque ele calcula exp a partir de time() real; e não queremos abrir
        // um override de TTL na API de produção só para o teste.
        $expired = \Firebase\JWT\JWT::encode(
            [
                'iss'    => '',
                'aud'    => 'habitaweb-api-v1',
                'sub'    => (string) $tenant['user']->id,
                'acc'    => (int) $tenant['account']->id,
                'key_id' => $tenant['key_id'],
                'typ'    => JwtManager::TYPE_ACCESS,
                'jti'    => bin2hex(random_bytes(16)),
                'iat'    => time() - 7200,
                'exp'    => time() - 3600,
            ],
            JwtManager::resolveSecret(),
            JwtManager::ALGO
        );

        $result = $this->withHeaders($this->withJwt($expired))->get('api/v1/properties');

        $result->assertStatus(401);
        $this->assertSame('TOKEN_EXPIRED', $this->envelope($result)['error_code']);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $tenant = $this->makeApiTenant();
        $jwt    = $this->tenants()->createJwt((int) $tenant['account']->id, (int) $tenant['user']->id);

        // Troca o último caractere da assinatura.
        $parts    = explode('.', $jwt['access_token']);
        $parts[2] = strrev($parts[2]);
        $tampered = implode('.', $parts);

        $result = $this->withHeaders($this->withJwt($tampered))->get('api/v1/properties');

        $result->assertStatus(401);
        $this->assertContains(
            $this->envelope($result)['error_code'],
            ['TOKEN_SIGNATURE_INVALID', 'TOKEN_MALFORMED']
        );
    }

    public function testRefreshTokenCannotBeUsedAsAccessToken(): void
    {
        $tenant = $this->makeApiTenant();
        $jwt    = $this->tenants()->createJwt((int) $tenant['account']->id, (int) $tenant['user']->id);

        $result = $this->withHeaders($this->withJwt($jwt['refresh_token']))->get('api/v1/properties');

        $result->assertStatus(401);
        $this->assertSame('TOKEN_WRONG_TYPE', $this->envelope($result)['error_code']);
    }

    public function testRevokingApiKeyInvalidatesItsJwt(): void
    {
        $tenant = $this->makeApiTenant();
        $jwt    = $this->tenants()->createJwt((int) $tenant['account']->id, (int) $tenant['user']->id);

        // Token funciona antes da revogação.
        $this->withHeaders($this->withJwt($jwt['access_token']))
            ->get('api/v1/properties')
            ->assertStatus(200);

        model(ApiKeyModel::class)->revokeKey($jwt['key_id']);
        $this->resetApiState();

        $result = $this->withHeaders($this->withJwt($jwt['access_token']))->get('api/v1/properties');

        $result->assertStatus(401);
        $this->assertSame('KEY_REVOKED', $this->envelope($result)['error_code']);
    }

    public function testAuthMeReportsAccountAndPlan(): void
    {
        $tenant = $this->makeApiTenant();

        $result = $this->withHeaders($this->withApiKey($tenant['api_key']))->get('api/v1/auth/me');

        $result->assertStatus(200);
        $data = $this->envelope($result)['data'];

        $this->assertSame((int) $tenant['account']->id, $data['account']['id']);
        $this->assertSame('api_key', $data['auth']['type']);
        $this->assertSame('PRATA', $data['plan']['chave']);
        $this->assertArrayHasKey('imoveis_ativos', $data['usage']);
    }

    public function testMalformedJsonReturns400NotServerError(): void
    {
        // getJSON() do CI4 lança HTTPException sem código HTTP nesse caso, o que
        // virava 500. Deve ser 400.
        $result = $this->withHeaders(['Content-Type' => 'application/json'])
            ->withBody('{"api_key": ')
            ->post('api/v1/auth/token');

        $result->assertStatus(400);
        $this->assertSame('INVALID_PAYLOAD', $this->envelope($result)['error_code']);
    }
}
