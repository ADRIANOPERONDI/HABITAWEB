<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;

/**
 * Base de todos os controllers da API v1.
 *
 * Padroniza UM envelope de resposta para toda a API. Antes desta classe conviviam
 * três formatos diferentes (respondSuccess, respondError e os helpers failNotFound/
 * respondCreated do ResponseTrait do CI4), o que obrigava o parceiro a tratar três
 * shapes distintos de erro. Agora todo controller V1 responde apenas via
 * respondSuccess() / respondError().
 */
class BaseController extends ResourceController
{
    /**
     * Códigos de erro estáveis. O parceiro deve programar contra estes valores,
     * nunca contra a mensagem em português (que pode mudar sem aviso).
     */
    public const ERR_UNAUTHORIZED     = 'UNAUTHORIZED';
    public const ERR_FORBIDDEN        = 'TENANT_FORBIDDEN';
    public const ERR_NOT_FOUND        = 'NOT_FOUND';
    public const ERR_INVALID_PAYLOAD  = 'INVALID_PAYLOAD';
    public const ERR_VALIDATION       = 'VALIDATION_FAILED';
    public const ERR_PLAN_LIMIT       = 'PLAN_LIMIT_REACHED';
    public const ERR_PHOTO_LIMIT      = 'PHOTO_LIMIT_REACHED';
    public const ERR_DUPLICATE        = 'DUPLICATE_EXTERNAL_ID';
    public const ERR_RATE_LIMITED     = 'RATE_LIMITED';
    public const ERR_INTERNAL         = 'INTERNAL_ERROR';

    /**
     * Mensagem do último erro de parsing de JSON, preenchida por getJsonBody().
     */
    protected ?string $jsonError = null;

    /**
     * Verifica se o usuário autenticado (via API) é superadmin, consultando o
     * grupo REAL do usuário — em vez do frágil "auth_user_id == 1", que quebra
     * se o ID 1 não for o superadmin (ex.: após reseed do banco).
     */
    protected function isSuperAdmin(): bool
    {
        $userId = (int) ($this->request->auth_user_id ?? 0);
        if ($userId <= 0) {
            return false;
        }

        return \Config\Database::connect()->table('auth_groups_users')
            ->where('user_id', $userId)
            ->where('group', 'superadmin')
            ->countAllResults() > 0;
    }

    /**
     * ID da conta autenticada (tenant). Retorna null quando a credencial não
     * está vinculada a nenhuma conta.
     */
    protected function currentAccountId(): ?int
    {
        $accountId = $this->request->auth_account_id ?? null;

        return $accountId ? (int) $accountId : null;
    }

    /**
     * Decodifica o corpo JSON sem explodir.
     *
     * IncomingRequest::getJSON() do CI 4.7 lança HTTPException SEM código HTTP
     * quando o corpo não é JSON válido — o que o CI renderiza como 500. Ou seja:
     * um parceiro que mandasse Content-Type errado ou corpo vazio recebia 500 em
     * vez de 400. Aqui decodificamos manualmente e devolvemos null em caso de erro,
     * deixando o controller responder 400 via respondInvalidJson().
     *
     * @return array|null Array decodificado, ou null se o corpo for inválido.
     */
    protected function getJsonBody(): ?array
    {
        $this->jsonError = null;

        $raw = (string) $this->request->getBody();

        // Corpo vazio é tratado como objeto vazio — quem exigir campos valida depois.
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->jsonError = 'JSON inválido: ' . json_last_error_msg();

            return null;
        }

        if (! is_array($decoded)) {
            $this->jsonError = 'O corpo da requisição deve ser um objeto ou array JSON.';

            return null;
        }

        return $decoded;
    }

    /**
     * Resposta 400 padronizada para corpo JSON malformado.
     */
    protected function respondInvalidJson()
    {
        return $this->respondError(
            $this->jsonError ?? 'Corpo da requisição inválido.',
            400,
            [],
            self::ERR_INVALID_PAYLOAD
        );
    }

    /**
     * True quando o cliente enviou o corpo como JSON.
     */
    protected function isJsonRequest(): bool
    {
        return str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'json');
    }

    /**
     * Helper para respostas de sucesso padronizadas.
     *
     * O status HTTP agora acompanha o campo "status" do corpo — antes era sempre
     * 200, inclusive em criações e falhas embrulhadas em sucesso.
     */
    protected function respondSuccess($data = null, string $message = 'Success', int $statusCode = 200)
    {
        return $this->respond([
            'status'  => $statusCode,
            'error'   => null,
            'message' => $message,
            'data'    => $data,
        ], $statusCode);
    }

    /**
     * Helper para respostas de erro padronizadas.
     *
     * @param string $errorCode Um dos ERR_* desta classe — contrato estável para o cliente.
     */
    protected function respondError(
        string $message,
        int $statusCode = 400,
        $errors = [],
        string $errorCode = self::ERR_VALIDATION
    ) {
        return $this->respond([
            'status'     => $statusCode,
            'error'      => $statusCode,
            'error_code' => $errorCode,
            'message'    => $message,
            'data'       => null,
            'details'    => $errors,
        ], $statusCode);
    }

    /**
     * Atalhos semânticos — mantêm o envelope único em vez dos helpers do ResponseTrait.
     */
    protected function respondNotFound(string $message = 'Recurso não encontrado.')
    {
        return $this->respondError($message, 404, [], self::ERR_NOT_FOUND);
    }

    protected function respondForbidden(string $message = 'Acesso negado.')
    {
        return $this->respondError($message, 403, [], self::ERR_FORBIDDEN);
    }
}
