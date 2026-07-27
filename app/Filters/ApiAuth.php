<?php

namespace App\Filters;

use App\Libraries\Auth\JwtManager;
use App\Models\ApiKeyModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Filtro de Autenticação para API.
 *
 * Aceita três credenciais no header Authorization: Bearer {token}
 *   1. API Key custom  — token começa com "pk_"           (parceiros, longa duração)
 *   2. JWT             — token com 3 segmentos separados por "." (curta duração, emitido em /auth/token)
 *   3. Shield token    — qualquer outro formato            (usuários internos, via spark)
 *
 * Em todos os casos injeta no request: auth_user_id, auth_account_id,
 * auth_account_type, auth_type e rate_limit.
 */
class ApiAuth implements FilterInterface
{
    /**
     * Cache por request da API Key já resolvida. ApiRateLimit roda ANTES deste
     * filtro e também precisa resolver a chave; sem este cache a mesma chave era
     * verificada com bcrypt duas vezes na mesma requisição.
     *
     * @var array<string, \App\Entities\ApiKey|null>
     */
    private static array $resolvedKeys = [];

    public function before(RequestInterface $request, $arguments = null)
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            return $this->unauthorizedResponse('Token de autenticação não fornecido.', 'MISSING_TOKEN');
        }

        // Formato esperado: "Bearer {token}"
        if (! preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $this->unauthorizedResponse(
                'Formato de autenticação inválido. Use: Authorization: Bearer {token}',
                'MALFORMED_HEADER'
            );
        }

        $token = trim($matches[1]);

        if ($token === '') {
            return $this->unauthorizedResponse('Token de autenticação vazio.', 'MISSING_TOKEN');
        }

        // API Key custom
        if (str_starts_with($token, 'pk_')) {
            return $this->authenticateViaApiKey($token, $request);
        }

        // JWT: header.payload.signature
        if (substr_count($token, '.') === 2) {
            return $this->authenticateViaJwt($token, $request);
        }

        // Shield token
        return $this->authenticateViaShieldToken($token, $request);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nada a fazer após
    }

    /**
     * Resolve uma API Key em texto claro, memoizando o resultado por request.
     * Usado tanto por este filtro quanto por ApiRateLimit.
     */
    public static function resolveApiKey(string $plainKey): ?\App\Entities\ApiKey
    {
        $cacheKey = sha1($plainKey);

        if (! array_key_exists($cacheKey, self::$resolvedKeys)) {
            self::$resolvedKeys[$cacheKey] = model(ApiKeyModel::class)->findByPlainKey($plainKey);
        }

        return self::$resolvedKeys[$cacheKey];
    }

    /**
     * Limpa o cache de chaves. Necessário entre testes, que reusam o processo.
     */
    public static function resetCache(): void
    {
        self::$resolvedKeys = [];
    }

    /**
     * Autentica via API Key custom
     */
    private function authenticateViaApiKey(string $plainKey, RequestInterface $request)
    {
        $apiKey = self::resolveApiKey($plainKey);

        if (! $apiKey) {
            return $this->unauthorizedResponse('API Key inválida.', 'INVALID_KEY');
        }

        if (! $apiKey->isActive()) {
            return $this->unauthorizedResponse('API Key inativa ou expirada.', 'INACTIVE_KEY');
        }

        $request->auth_user_id      = $apiKey->user_id;
        $request->auth_account_id   = $apiKey->account_id;
        $request->auth_account_type = $this->accountType($apiKey->account_id);
        $request->auth_type         = 'api_key';
        $request->auth_api_key_id   = $apiKey->id;
        $request->rate_limit        = $apiKey->rate_limit_per_hour;

        $this->trackUsage($apiKey, $request);

        return null; // Autorizado
    }

    /**
     * Autentica via JWT emitido por /api/v1/auth/token.
     */
    private function authenticateViaJwt(string $token, RequestInterface $request)
    {
        try {
            $result = (new JwtManager())->verify($token, JwtManager::TYPE_ACCESS);
        } catch (\RuntimeException $e) {
            // Segredo de assinatura não configurado — é erro de servidor, não do cliente.
            log_message('critical', '[ApiAuth] JWT indisponível: ' . $e->getMessage());

            return $this->serverErrorResponse('Autenticação JWT indisponível nesta instalação.');
        }

        if (! $result['valid']) {
            return $this->unauthorizedResponse($result['error'], $result['code'] ?? 'INVALID_TOKEN');
        }

        $payload  = $result['payload'];
        $apiKeyId = $payload['key_id'] ?? null;

        // O JWT sobrevive à revogação da chave que o emitiu (é stateless), então
        // revalidamos a chave de origem aqui. Sem isso, revogar uma chave no painel
        // não teria efeito até o token expirar.
        $rateLimit = 1000;

        if ($apiKeyId) {
            $apiKey = model(ApiKeyModel::class)->find($apiKeyId);

            if (! $apiKey || ! $apiKey->isActive()) {
                return $this->unauthorizedResponse(
                    'A API Key que originou este token foi revogada.',
                    'KEY_REVOKED'
                );
            }

            $rateLimit = $apiKey->rate_limit_per_hour ?: 1000;
        }

        $userId = isset($payload['sub']) && (int) $payload['sub'] > 0 ? (int) $payload['sub'] : null;

        $request->auth_user_id      = $userId;
        $request->auth_account_id   = (int) $payload['acc'];
        $request->auth_account_type = $this->accountType((int) $payload['acc']);
        $request->auth_type         = 'jwt';
        $request->auth_api_key_id   = $apiKeyId;
        $request->rate_limit        = $rateLimit;

        return null; // Autorizado
    }

    /**
     * Autentica via Shield Token (fallback)
     */
    private function authenticateViaShieldToken(string $token, RequestInterface $request)
    {
        $result = auth('tokens')->check(['token' => $token]);

        if (! $result->isOK()) {
            return $this->unauthorizedResponse('Token Shield inválido ou expirado.', 'INVALID_TOKEN');
        }

        // CodeIgniter\Shield\Result devolve o User em extraInfo(), não em
        // getUser() — que não existe na classe. O código anterior chamava
        // getUser() e portanto dava fatal em TODO token Shield válido: a via de
        // autenticação inteira estava quebrada e sem cobertura de teste.
        $user = $result->extraInfo();

        if (! $user instanceof \CodeIgniter\Shield\Entities\User) {
            log_message('error', '[ApiAuth] Shield retornou sucesso sem usuário no extraInfo.');

            return $this->unauthorizedResponse('Token Shield inválido ou expirado.', 'INVALID_TOKEN');
        }

        $request->auth_user_id      = $user->id;
        $request->auth_account_id   = $user->account_id ?? null;
        $request->auth_account_type = $this->accountType($user->account_id ?? null);
        $request->auth_type         = 'shield_token';
        $request->auth_api_key_id   = null;
        $request->rate_limit        = 5000; // Limite maior para tokens de usuário

        return null; // Autorizado
    }

    /**
     * Tipo da conta (PF / IMOBILIARIA / CORRETOR), normalizado em minúsculas.
     *
     * A coluna é 'tipo_conta'. O código anterior lia $account->type — atributo
     * que não existe na entidade nem na tabela — então auth_account_type era
     * SEMPRE null, o que deixava POST /api/v1/accounts inalcançável para
     * qualquer não-superadmin.
     */
    private function accountType($accountId): ?string
    {
        if (! $accountId) {
            return null;
        }

        $account = model('App\Models\AccountModel')->find($accountId);

        if (! $account) {
            return null;
        }

        $type = is_object($account) ? ($account->tipo_conta ?? null) : ($account['tipo_conta'] ?? null);

        return $type ? strtolower((string) $type) : null;
    }

    /**
     * Registra último uso da chave. ApiKey::updateUsage() existia desde sempre
     * mas nunca era chamado — last_used_at/last_used_ip eram colunas mortas.
     * Falha aqui nunca deve derrubar a requisição.
     */
    private function trackUsage(\App\Entities\ApiKey $apiKey, RequestInterface $request): void
    {
        try {
            model(ApiKeyModel::class)->update($apiKey->id, [
                'last_used_at' => date('Y-m-d H:i:s'),
                'last_used_ip' => $request->getIPAddress(),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', '[ApiAuth] falha ao registrar uso da API key: ' . $e->getMessage());
        }
    }

    /**
     * Resposta de erro 401 Unauthorized, no mesmo envelope dos controllers V1.
     */
    private function unauthorizedResponse(string $message, string $errorCode = 'UNAUTHORIZED')
    {
        return service('response')
            ->setStatusCode(401)
            ->setJSON([
                'status'     => 401,
                'error'      => 401,
                'error_code' => $errorCode,
                'message'    => $message,
                'data'       => null,
                'details'    => [],
            ]);
    }

    private function serverErrorResponse(string $message)
    {
        return service('response')
            ->setStatusCode(500)
            ->setJSON([
                'status'     => 500,
                'error'      => 500,
                'error_code' => 'INTERNAL_ERROR',
                'message'    => $message,
                'data'       => null,
                'details'    => [],
            ]);
    }
}
