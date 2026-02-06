<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\ApiKeyModel;

/**
 * Filtro de Autenticação para API
 * Valida API Key ou Shield Token no header Authorization
 */
class ApiAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            return $this->unauthorizedResponse('Token de autenticação não fornecido.');
        }

        // Formato esperado: "Bearer {token}"
        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $this->unauthorizedResponse('Formato de autenticação inválido. Use: Authorization: Bearer {token}');
        }

        $token = $matches[1];

        // Tenta autenticar via API Key custom
        if (str_starts_with($token, 'pk_')) {
            return $this->authenticateViaApiKey($token, $request);
        }

        // Caso contrário, tenta autenticar via Shield Token
        return $this->authenticateViaShieldToken($token, $request);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nada a fazer após
    }

    /**
     * Autentica via API Key custom
     */
    private function authenticateViaApiKey(string $plainKey, RequestInterface $request)
    {
        $apiKeyModel = model(ApiKeyModel::class);
        $apiKey = $apiKeyModel->findByPlainKey($plainKey);

        if (!$apiKey) {
            return $this->unauthorizedResponse('API Key inválida.');
        }

        if (!$apiKey->isActive()) {
            return $this->unauthorizedResponse('API Key inativa ou expirada.');
        }

        // Carrega o tipo da conta para facilitar permissões nos controllers
        $account = model('App\Models\AccountModel')->find($apiKey->account_id);
        $accountType = $account ? (is_object($account) ? $account->type : $account['type']) : 'pf';

        // Injeta informações no request para uso posterior
        $request->auth_user_id = $apiKey->user_id;
        $request->auth_account_id = $apiKey->account_id;
        $request->auth_account_type = $accountType;
        $request->auth_type = 'api_key';
        $request->rate_limit = $apiKey->rate_limit_per_hour;

        return null; // Autorizado
    }

    /**
     * Autentica via Shield Token (fallback)
     */
    private function authenticateViaShieldToken(string $token, RequestInterface $request)
    {
        // Usa autenticação do Shield
        $authenticator = auth('tokens')->check(['token' => $token]);

        if (!$authenticator->isOK()) {
            return $this->unauthorizedResponse('Token Shield inválido ou expirado.');
        }

        $user = $authenticator->getUser();
        $account = model('App\Models\AccountModel')->find($user->account_id);
        $accountType = $account ? (is_object($account) ? $account->type : $account['type']) : 'pf';

        // Injeta informações no request
        $request->auth_user_id = $user->id;
        $request->auth_account_id = $user->account_id ?? null;
        $request->auth_account_type = $accountType;
        $request->auth_type = 'shield_token';
        $request->rate_limit = 5000; // Limite maior para tokens de usuário

        return null; // Autorizado
    }

    /**
     * Resposta de erro 401 Unauthorized
     */
    private function unauthorizedResponse(string $message)
    {
        $response = service('response');
        $response->setStatusCode(401);
        $response->setJSON([
            'error' => 'Unauthorized',
            'message' => $message
        ]);
        return $response;
    }
}
