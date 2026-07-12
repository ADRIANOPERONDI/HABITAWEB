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
        if (file_exists($path)) {
            $json = file_get_contents($path);
            return $this->response->setJSON(json_decode($json, true));
        }

        return $this->response->setJSON(['error' => 'OpenAPI file not found']);
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
