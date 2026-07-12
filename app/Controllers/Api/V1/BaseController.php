<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;

class BaseController extends ResourceController
{
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
     * Helper para respostas de sucesso padronizadas.
     */
    protected function respondSuccess($data = null, string $message = 'Success')
    {
        return $this->respond([
            'status'  => 200,
            'error'   => null,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    /**
     * Helper para respostas de erro padronizadas.
     */
    protected function respondError(string $message, int $statusCode = 400, $errors = [])
    {
        return $this->respond([
            'status'  => $statusCode,
            'error'   => $statusCode,
            'message' => $message,
            'data'    => null, // ou 'errors' => $errors dependendo do gosto, mas vamos seguir padrão do CI4 RESTful trait response se possível, mas aqui estamos customizando
            'details' => $errors
        ], $statusCode);
    }
}
