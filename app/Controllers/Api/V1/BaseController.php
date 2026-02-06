<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\RESTful\ResourceController;

class BaseController extends ResourceController
{
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
