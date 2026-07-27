<?php

namespace App\Controllers\Api\V1;

use App\Libraries\Auth\JwtManager;
use App\Models\ApiKeyModel;

/**
 * Emissão e ciclo de vida dos tokens JWT da API v1.
 *
 * O parceiro tem duas opções de credencial, e ambas são válidas:
 *  - Mandar a API Key (pk_...) direto no Authorization de toda requisição.
 *    Simples, sem estado, bom para integração servidor-a-servidor.
 *  - Trocar a API Key por um JWT curto aqui e usar o JWT. Melhor quando o
 *    token vai trafegar por clientes menos confiáveis (app mobile, front-end),
 *    porque expira em 1h e não expõe a credencial de longa duração.
 */
class AuthController extends BaseController
{
    /**
     * POST /api/v1/auth/token
     * Troca uma API Key por um par access/refresh token.
     */
    public function token()
    {
        $data = $this->getJsonBody();

        if ($data === null) {
            return $this->respondInvalidJson();
        }

        // Aceita a chave no corpo ou no header Authorization, o que for mais
        // conveniente para o cliente.
        $plainKey = $data['api_key'] ?? null;

        if (empty($plainKey) && preg_match('/Bearer\s+(.*)$/i', $this->request->getHeaderLine('Authorization'), $m)) {
            $plainKey = trim($m[1]);
        }

        if (empty($plainKey) || ! is_string($plainKey)) {
            return $this->respondError(
                'Informe a API Key no campo "api_key" ou no header Authorization.',
                400,
                [],
                self::ERR_INVALID_PAYLOAD
            );
        }

        $apiKey = model(ApiKeyModel::class)->findByPlainKey($plainKey);

        if (! $apiKey || ! $apiKey->isActive()) {
            // Mensagem deliberadamente genérica: não revelar se a chave existe.
            return $this->respondError('API Key inválida ou inativa.', 401, [], self::ERR_UNAUTHORIZED);
        }

        return $this->respondSuccess(
            $this->buildTokenPair((int) $apiKey->account_id, $apiKey->user_id ? (int) $apiKey->user_id : null, (int) $apiKey->id),
            'Token emitido com sucesso.'
        );
    }

    /**
     * POST /api/v1/auth/refresh
     * Rotaciona o par de tokens. O refresh token antigo é revogado no processo —
     * reuso de um refresh já usado é rejeitado, que é a defesa padrão contra
     * roubo de refresh token.
     */
    public function refresh()
    {
        $data = $this->getJsonBody();

        if ($data === null) {
            return $this->respondInvalidJson();
        }

        $refreshToken = $data['refresh_token'] ?? null;

        if (empty($refreshToken) || ! is_string($refreshToken)) {
            return $this->respondError('Informe o campo "refresh_token".', 400, [], self::ERR_INVALID_PAYLOAD);
        }

        $jwt    = new JwtManager();
        $result = $jwt->verifyRefreshToken($refreshToken);

        if (! $result['valid']) {
            return $this->respondError($result['error'], 401, [], $result['code'] ?? self::ERR_UNAUTHORIZED);
        }

        $payload  = $result['payload'];
        $apiKeyId = $payload['key_id'] ?? null;

        // A chave que originou o token pode ter sido revogada depois da emissão.
        if ($apiKeyId) {
            $apiKey = model(ApiKeyModel::class)->find($apiKeyId);

            if (! $apiKey || ! $apiKey->isActive()) {
                $jwt->revokeByApiKey((int) $apiKeyId);

                return $this->respondError('A API Key que originou este token foi revogada.', 401, [], self::ERR_UNAUTHORIZED);
            }
        }

        // Rotação: invalida o refresh usado antes de emitir o novo par.
        $jwt->revokeRefreshToken($payload['jti']);

        $userId = isset($payload['sub']) && (int) $payload['sub'] > 0 ? (int) $payload['sub'] : null;

        return $this->respondSuccess(
            $this->buildTokenPair((int) $payload['acc'], $userId, $apiKeyId ? (int) $apiKeyId : null),
            'Token renovado com sucesso.'
        );
    }

    /**
     * POST /api/v1/auth/revoke
     * Invalida um refresh token (logout). Idempotente.
     *
     * Nota: o access token continua válido até expirar (no máximo 1h) — é a
     * contrapartida esperada de um JWT stateless.
     */
    public function revoke()
    {
        $data = $this->getJsonBody();

        if ($data === null) {
            return $this->respondInvalidJson();
        }

        $refreshToken = $data['refresh_token'] ?? null;

        if (empty($refreshToken) || ! is_string($refreshToken)) {
            return $this->respondError('Informe o campo "refresh_token".', 400, [], self::ERR_INVALID_PAYLOAD);
        }

        $jwt    = new JwtManager();
        $result = $jwt->verify($refreshToken, JwtManager::TYPE_REFRESH);

        if ($result['valid']) {
            $jwt->revokeRefreshToken($result['payload']['jti']);
        }

        // Sempre 200: não revelar se o token existia/era válido.
        return $this->respondSuccess(null, 'Token revogado.');
    }

    /**
     * GET /api/v1/auth/me
     * Endpoint de diagnóstico do parceiro: confirma qual conta a credencial
     * representa, qual o plano e quanto resta da cota de requisições.
     */
    public function me()
    {
        $accountId = $this->currentAccountId();

        if (! $accountId) {
            return $this->respondForbidden('Credencial não vinculada a uma conta.');
        }

        $account = model('App\Models\AccountModel')->find($accountId);

        if (! $account) {
            return $this->respondNotFound('Conta não encontrada.');
        }

        $plan = null;
        $subscription = \Config\Database::connect()->table('subscriptions')
            ->select('subscriptions.status, subscriptions.data_fim, plans.nome, plans.chave, plans.limite_imoveis_ativos, plans.limite_fotos_por_imovel')
            ->join('plans', 'plans.id = subscriptions.plan_id', 'left')
            ->where('subscriptions.account_id', $accountId)
            ->whereIn('subscriptions.status', ['ACTIVE', 'TRIAL'])
            ->orderBy('subscriptions.id', 'DESC')
            ->get()
            ->getRowArray();

        if ($subscription) {
            $plan = [
                'nome'                    => $subscription['nome'],
                'chave'                   => $subscription['chave'],
                'status_assinatura'       => $subscription['status'],
                'valido_ate'              => $subscription['data_fim'],
                'limite_imoveis_ativos'   => $subscription['limite_imoveis_ativos'] !== null
                    ? (int) $subscription['limite_imoveis_ativos']
                    : null,
                'limite_fotos_por_imovel' => $subscription['limite_fotos_por_imovel'] !== null
                    ? (int) $subscription['limite_fotos_por_imovel']
                    : null,
            ];
        }

        $activeProperties = model('App\Models\PropertyModel')
            ->where('account_id', $accountId)
            ->where('status', 'ACTIVE')
            ->countAllResults();

        return $this->respondSuccess([
            'account' => [
                'id'          => (int) $account->id,
                'nome'        => $account->nome,
                'email'       => $account->email,
                'tipo_conta'  => $account->tipo_conta,
                'is_verified' => (bool) $account->is_verified,
            ],
            'auth' => [
                'type'       => $this->request->auth_type ?? null,
                'user_id'    => $this->request->auth_user_id ?? null,
                'rate_limit' => $this->request->rate_limit ?? null,
            ],
            'plan'  => $plan,
            'usage' => [
                'imoveis_ativos' => $activeProperties,
            ],
        ]);
    }

    /**
     * Monta o par access + refresh no formato OAuth2-like que a maioria dos
     * clientes HTTP já sabe consumir.
     */
    private function buildTokenPair(int $accountId, ?int $userId, ?int $apiKeyId): array
    {
        $jwt = new JwtManager();

        $access  = $jwt->issueAccessToken($accountId, $userId, $apiKeyId);
        $refresh = $jwt->issueRefreshToken($accountId, $userId, $apiKeyId);

        return [
            'token_type'            => 'Bearer',
            'access_token'          => $access['token'],
            'expires_in'            => $access['expires_in'],
            'expires_at'            => $access['expires_at'],
            'refresh_token'         => $refresh['token'],
            'refresh_expires_in'    => $refresh['expires_in'],
            'refresh_expires_at'    => $refresh['expires_at'],
            'account_id'            => $accountId,
        ];
    }
}
