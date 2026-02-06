<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class InstallationCheck implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Verifica se o sistema está instalado
        $isInstalled = file_exists(WRITEPATH . '.installed');

        // Pega a URI atual
        $uri = $request->getUri()->getPath();

        // Se NÃO está instalado E não está acessando rota de instalação
        if (!$isInstalled && !str_starts_with($uri, '/install')) {
            // Redireciona para o instalador
            return redirect()->to('/install');
        }

        // Se está instalado E está tentando acessar /install
        if ($isInstalled && str_starts_with($uri, '/install')) {
            // Redireciona para a home (sistema já instalado)
            return redirect()->to('/');
        }

        // Permite a requisição continuar normalmente
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nada a fazer após a requisição
    }
}
