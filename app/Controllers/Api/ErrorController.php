<?php

namespace App\Controllers\Api;

use CodeIgniter\Controller;

/**
 * Handler de 404 do app.
 *
 * Registrado via set404Override() em app/Config/Routes.php. Sob /api/* devolve
 * o mesmo envelope JSON dos demais erros da API — sem isso o cliente do
 * parceiro recebia a página HTML de erro do framework e quebrava ao tentar
 * fazer o parse. Fora de /api/*, mantém a página HTML normal.
 *
 * É um controller (e não um Closure) de propósito: para um Closure o
 * CodeIgniter faz `echo $override(...)`, tratando o retorno como corpo bruto;
 * para um controller ele respeita um ResponseInterface retornado, o que
 * preserva status e Content-Type (ver CodeIgniter::gatherOutput()).
 */
class ErrorController extends Controller
{
    public function notFound(?string $message = null)
    {
        $path = ltrim(service('request')->getPath(), '/');

        if (! str_starts_with($path, 'api/')) {
            // A view espera $message (usa nl2br(esc($message))); sem passar,
            // ela quebra com "Undefined variable $message".
            return $this->response
                ->setStatusCode(404)
                ->setBody(view('errors/html/error_404', [
                    'message' => $message ?: lang('Errors.pageNotFound'),
                ]));
        }

        return $this->response
            ->setStatusCode(404)
            ->setContentType('application/json')
            ->setJSON([
                'status'     => 404,
                'error'      => 404,
                'error_code' => 'NOT_FOUND',
                'message'    => 'Endpoint não encontrado. Confira a documentação em /api/docs.',
                'data'       => null,
                'details'    => [],
            ]);
    }
}
