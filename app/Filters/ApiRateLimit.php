<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Filtro de Rate Limiting para API
 * Limita requisições por hora baseado em API Key ou IP
 */
class ApiRateLimit implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Determina o identificador para rate limit
        $identifier = $this->getIdentifier($request);
        
        // Sanitiza o identificador para evitar caracteres reservados no Cache (ex: IPv6 ::1)
        $identifier = str_replace([':', '{', '}', '(', ')', '/', '\\', '@'], '_', $identifier);
        
        $limit = $this->getLimit($request);

        // Usa cache para armazenar contadores
        $cache = \Config\Services::cache();
        $cacheKey = "rate_limit_{$identifier}";
        $windowKey = "rate_limit_window_{$identifier}";

        // Pega contador atual
        $count = $cache->get($cacheKey) ?? 0;
        $windowStart = $cache->get($windowKey);

        // Se a janela não existe ou expirou, cria nova
        $now = time();
        if (!$windowStart || ($now - $windowStart) >= 3600) {
            $count = 0;
            $cache->save($windowKey, $now, 3600);
        }

        // Incrementa contador
        $count++;
        $cache->save($cacheKey, $count, 3600);

        // Calcula valores para headers
        $remaining = max(0, $limit - $count);
        $resetTime = $windowStart + 3600;

        // Adiciona headers de rate limit à resposta
        $response = service('response');
        $response->setHeader('X-RateLimit-Limit', (string)$limit);
        $response->setHeader('X-RateLimit-Remaining', (string)$remaining);
        $response->setHeader('X-RateLimit-Reset', (string)$resetTime);

        // Se excedeu o limite, retorna 429
        if ($count > $limit) {
            $response->setStatusCode(429);
            $response->setJSON([
                'error' => 'Too Many Requests',
                'message' => 'Você excedeu o limite de requisições. Tente novamente em ' . $this->formatTimeRemaining($resetTime - $now) . '.',
                'retry_after' => $resetTime - $now
            ]);
            return $response;
        }

        return null; // Dentro do limite
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Nada a fazer após
    }

    /**
     * Determina o identificador único para rate limiting
     */
    private function getIdentifier(RequestInterface $request): string
    {
        // Se autenticado via API Key, usa o ID da chave
        if (property_exists($request, 'api_key_id')) {
            return 'key_' . $request->api_key_id;
        }

        // Se autenticado via Shield Token, usa o user_id
        if (property_exists($request, 'user_id')) {
            return 'user_' . $request->user_id;
        }

        // Caso contrário, usa IP (para rotas públicas)
        return 'ip_' . $request->getIPAddress();
    }

    /**
     * Determina o limite de requisições
     */
    private function getLimit(RequestInterface $request): int
    {
        // Se tem rate_limit customizado (de API Key), usa
        if (property_exists($request, 'rate_limit')) {
            return $request->rate_limit;
        }

        // Se é usuário autenticado via Shield, usa limite padrão
        if (property_exists($request, 'user_id')) {
            return 5000; // Usuários autenticados: 5000/hora
        }

        // IP não autenticado: limite baixo
        return 100; // 100/hora
    }

    /**
     * Formata tempo restante de forma legível
     */
    private function formatTimeRemaining(int $seconds): string
    {
        $minutes = ceil($seconds / 60);
        if ($minutes < 60) {
            return "{$minutes} minuto(s)";
        }
        $hours = ceil($minutes / 60);
        return "{$hours} hora(s)";
    }
}
