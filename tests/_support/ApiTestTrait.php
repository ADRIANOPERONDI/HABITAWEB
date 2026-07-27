<?php

namespace Tests\Support;

use App\Filters\ApiAuth;
use Tests\Support\Factories\TenantFactory;

/**
 * Utilidades comuns às suítes de API.
 *
 * Antes disso cada teste montava o header Authorization à mão e reimplementava
 * o setup de tenant + chave. Também centraliza duas armadilhas do ambiente de
 * teste que já custaram tempo:
 *
 *  - o cache de rate limit vive no Redis e NÃO é revertido pelo rollback da
 *    transação do banco, então um teste de 429 contamina o seguinte;
 *  - ApiAuth memoiza chaves resolvidas por processo, e o PHPUnit reusa o
 *    processo entre testes.
 */
trait ApiTestTrait
{
    protected ?TenantFactory $tenantFactory = null;

    protected function tenants(): TenantFactory
    {
        return $this->tenantFactory ??= new TenantFactory();
    }

    /**
     * Cria um tenant completo já com API key pronta para uso.
     *
     * @return array{account: mixed, user: mixed, subscription: mixed, password: string, api_key: string, key_id: int}
     */
    protected function makeApiTenant(array $overrides = [], string $planKey = 'PRATA', ?int $rateLimit = 1000): array
    {
        $tenant = $this->tenants()->create($overrides, $planKey);
        $key    = $this->tenants()->createApiKeyWithId(
            (int) $tenant['account']->id,
            (int) $tenant['user']->id,
            $rateLimit
        );

        $tenant['api_key'] = $key['plain_key'];
        $tenant['key_id']  = $key['key_id'];

        return $tenant;
    }

    /**
     * Header de autenticação por API Key.
     */
    protected function withApiKey(string $plainKey): array
    {
        return ['Authorization' => 'Bearer ' . $plainKey];
    }

    /**
     * Header de autenticação por JWT.
     */
    protected function withJwt(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Header para envio de corpo JSON. O CI4 só popula getBody()/getJSON()
     * corretamente quando o Content-Type indica JSON.
     */
    protected function jsonHeaders(string $plainKey): array
    {
        return [
            'Authorization' => 'Bearer ' . $plainKey,
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Envia uma requisição com corpo JSON REAL (string crua).
     *
     * Importante não usar withBodyFormat('json') + array de params aqui:
     * FeatureTestTrait::populateGlobals() também joga esse array em $_POST com
     * os tipos nativos do PHP (int, bool). Requisição HTTP de verdade nunca faz
     * isso — $_POST é sempre string, e num POST JSON ele nem existe. O efeito
     * colateral é que o filtro global invalidchars quebra com
     * "mb_check_encoding(): Argument #1 must be of type array|string|null, int
     * given" para qualquer payload com número — um falso positivo que não
     * acontece em produção. Mandando o corpo cru, o teste exercita exatamente o
     * que o parceiro vai mandar.
     */
    protected function postJson(string $uri, array $payload, ?string $credential = null)
    {
        return $this->jsonRequest('post', $uri, $payload, $credential);
    }

    protected function putJson(string $uri, array $payload, ?string $credential = null)
    {
        return $this->jsonRequest('put', $uri, $payload, $credential);
    }

    protected function patchJson(string $uri, array $payload, ?string $credential = null)
    {
        return $this->jsonRequest('patch', $uri, $payload, $credential);
    }

    private function jsonRequest(string $method, string $uri, array $payload, ?string $credential)
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($credential !== null) {
            $headers['Authorization'] = 'Bearer ' . $credential;
        }

        return $this->withHeaders($headers)
            ->withBody(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->{$method}($uri);
    }

    /**
     * Limpa estado que sobrevive ao rollback do banco. Chamar no setUp das
     * suítes de API.
     */
    protected function resetApiState(): void
    {
        ApiAuth::resetCache();

        try {
            \Config\Services::cache()->clean();
        } catch (\Throwable $e) {
            // Cache indisponível no ambiente de teste não deve derrubar a suíte.
        }
    }

    /**
     * Decodifica o envelope padrão da API v1 a partir da última resposta.
     */
    protected function envelope($result): array
    {
        $decoded = json_decode($result->getJSON() ?: '{}', true);

        return is_array($decoded) ? $decoded : [];
    }
}
