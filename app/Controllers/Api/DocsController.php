<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;

class DocsController extends BaseController
{
    public function index()
    {
        return view('api/swagger');
    }

    public function json()
    {
        $path = FCPATH . 'openapi.json';

        if (! is_file($path)) {
            // Antes respondia com HTTP 200 e um corpo {"error": ...}, o que faz
            // o Swagger UI (e qualquer gerador de client) tentar interpretar a
            // falha como se fosse um spec válido.
            return $this->response
                ->setStatusCode(404)
                ->setJSON(['error' => 'OpenAPI specification not found.']);
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            log_message('error', '[API Docs] openapi.json inválido: ' . json_last_error_msg());

            return $this->response
                ->setStatusCode(500)
                ->setJSON(['error' => 'OpenAPI specification is malformed.']);
        }

        return $this->response
            ->setHeader('Cache-Control', 'public, max-age=300')
            ->setJSON($decoded);
    }

    /**
     * REMOVIDO POR SEGURANÇA.
     *
     * Este método era exposto publicamente em GET /api/test-suite, sem autenticação, e:
     *  - rodava migrações do banco sob demanda a cada requisição;
     *  - gerava e retornava em texto plano uma API key válida e permanente para a
     *    primeira conta do sistema, permitindo a qualquer visitante anônimo obter
     *    acesso total à API.
     *
     * A rota foi removida em app/Config/Routes.php e o corpo foi neutralizado para
     * não deixar código perigoso acessível caso a rota seja acidentalmente reintroduzida.
     */
    public function testSuite()
    {
        return $this->response->setStatusCode(404)->setJSON(['error' => 'Not found']);
    }
}
