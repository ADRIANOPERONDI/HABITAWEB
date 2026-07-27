<?php

namespace App\Libraries\Auth;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

/**
 * Emissão e verificação dos JWT da API v1.
 *
 * Por que uma implementação própria em vez do authenticator JWT do Shield:
 * o do Shield exige um Config\AuthJWT (inexistente aqui) e é ancorado no
 * usuário Shield — enquanto toda a autorização da API é ancorada no
 * account_id (tenant) que vive na tabela api_keys. Emitir o token a partir
 * da API Key mantém uma única fonte de verdade para tenant, rate limit e
 * revogação.
 *
 * O access token é stateless (não há consulta ao banco para validá-lo, só
 * verificação de assinatura). O refresh token é stateful: guardamos o hash
 * na tabela api_refresh_tokens para poder revogar.
 */
class JwtManager
{
    public const ALGO = 'HS256';

    public const TYPE_ACCESS  = 'access';
    public const TYPE_REFRESH = 'refresh';

    /** Access token curto — 1 hora. */
    public const ACCESS_TTL = 3600;

    /** Refresh token longo — 30 dias. */
    public const REFRESH_TTL = 2592000;

    private string $secret;
    private string $issuer;

    public function __construct(?string $secret = null)
    {
        $this->secret = $secret ?? self::resolveSecret();
        $this->issuer = rtrim((string) config('App')->baseURL, '/');
    }

    /**
     * Segredo de assinatura. Prioriza JWT_SECRET do .env; se ausente, deriva
     * de encryption.key — assim a API funciona out-of-the-box sem uma etapa
     * extra de setup, mas continua sendo um segredo por instalação.
     */
    public static function resolveSecret(): string
    {
        $secret = env('JWT_SECRET');

        if (! empty($secret)) {
            return (string) $secret;
        }

        $encryptionKey = (string) (config('Encryption')->key ?? '');

        if ($encryptionKey === '') {
            throw new \RuntimeException(
                'Não há JWT_SECRET nem encryption.key configurados — impossível assinar tokens. '
                . 'Defina JWT_SECRET no .env.'
            );
        }

        // Deriva uma chave separada para não reusar literalmente a chave de
        // criptografia da aplicação em outro contexto.
        return hash_hmac('sha256', 'habitaweb-api-jwt', $encryptionKey);
    }

    /**
     * Emite um access token para um par (conta, usuário), amarrado à API Key
     * que o originou — o claim key_id permite ao rate limiter aplicar a mesma
     * cota da chave e permite revogar todos os tokens de uma chave revogada.
     */
    public function issueAccessToken(int $accountId, ?int $userId, ?int $apiKeyId = null, array $extra = []): array
    {
        $issuedAt = time();
        $expires  = $issuedAt + self::ACCESS_TTL;

        $payload = array_merge($extra, [
            'iss'    => $this->issuer,
            'aud'    => 'habitaweb-api-v1',
            'sub'    => (string) ($userId ?? 0),
            'acc'    => $accountId,
            'key_id' => $apiKeyId,
            'typ'    => self::TYPE_ACCESS,
            'jti'    => bin2hex(random_bytes(16)),
            'iat'    => $issuedAt,
            'exp'    => $expires,
        ]);

        return [
            'token'      => JWT::encode($payload, $this->secret, self::ALGO),
            'expires_in' => self::ACCESS_TTL,
            'expires_at' => date('c', $expires),
        ];
    }

    /**
     * Emite um refresh token e persiste o hash para permitir revogação.
     */
    public function issueRefreshToken(int $accountId, ?int $userId, ?int $apiKeyId = null): array
    {
        $issuedAt = time();
        $expires  = $issuedAt + self::REFRESH_TTL;
        $jti      = bin2hex(random_bytes(16));

        $payload = [
            'iss'    => $this->issuer,
            'aud'    => 'habitaweb-api-v1',
            'sub'    => (string) ($userId ?? 0),
            'acc'    => $accountId,
            'key_id' => $apiKeyId,
            'typ'    => self::TYPE_REFRESH,
            'jti'    => $jti,
            'iat'    => $issuedAt,
            'exp'    => $expires,
        ];

        $token = JWT::encode($payload, $this->secret, self::ALGO);

        \Config\Database::connect()->table('api_refresh_tokens')->insert([
            'account_id' => $accountId,
            'user_id'    => $userId,
            'api_key_id' => $apiKeyId,
            'jti'        => $jti,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', $expires),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'token'      => $token,
            'expires_in' => self::REFRESH_TTL,
            'expires_at' => date('c', $expires),
        ];
    }

    /**
     * Verifica assinatura, expiração e tipo do token.
     *
     * @return array{valid: bool, payload?: array, error?: string, code?: string}
     */
    public function verify(string $token, string $expectedType = self::TYPE_ACCESS): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, self::ALGO));
        } catch (ExpiredException $e) {
            return ['valid' => false, 'error' => 'Token expirado.', 'code' => 'TOKEN_EXPIRED'];
        } catch (SignatureInvalidException $e) {
            return ['valid' => false, 'error' => 'Assinatura do token inválida.', 'code' => 'TOKEN_SIGNATURE_INVALID'];
        } catch (\Throwable $e) {
            return ['valid' => false, 'error' => 'Token malformado.', 'code' => 'TOKEN_MALFORMED'];
        }

        $payload = (array) $decoded;

        if (($payload['typ'] ?? null) !== $expectedType) {
            return [
                'valid' => false,
                'error' => sprintf('Tipo de token inválido: esperado "%s".', $expectedType),
                'code'  => 'TOKEN_WRONG_TYPE',
            ];
        }

        if (empty($payload['acc'])) {
            return ['valid' => false, 'error' => 'Token sem conta vinculada.', 'code' => 'TOKEN_NO_ACCOUNT'];
        }

        return ['valid' => true, 'payload' => $payload];
    }

    /**
     * Verifica um refresh token: além da assinatura, exige que ele ainda exista
     * e não tenha sido revogado na tabela — é o que permite logout/rotação.
     */
    public function verifyRefreshToken(string $token): array
    {
        $result = $this->verify($token, self::TYPE_REFRESH);

        if (! $result['valid']) {
            return $result;
        }

        $row = \Config\Database::connect()->table('api_refresh_tokens')
            ->where('jti', $result['payload']['jti'] ?? '')
            ->where('revoked_at', null)
            ->get()
            ->getRowArray();

        if (! $row) {
            return ['valid' => false, 'error' => 'Refresh token revogado ou desconhecido.', 'code' => 'TOKEN_REVOKED'];
        }

        return $result;
    }

    /**
     * Revoga um refresh token pelo seu jti. Idempotente.
     */
    public function revokeRefreshToken(string $jti): bool
    {
        return \Config\Database::connect()->table('api_refresh_tokens')
            ->where('jti', $jti)
            ->where('revoked_at', null)
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Revoga todos os refresh tokens emitidos a partir de uma API Key.
     * Chamado quando a chave é revogada/desativada no painel — sem isso, um
     * refresh token sobreviveria à revogação da chave que o originou.
     */
    public function revokeByApiKey(int $apiKeyId): bool
    {
        return \Config\Database::connect()->table('api_refresh_tokens')
            ->where('api_key_id', $apiKeyId)
            ->where('revoked_at', null)
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);
    }
}
