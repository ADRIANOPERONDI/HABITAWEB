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
        // Ignorar rate limit para a rota de mapa pública
        $path = ltrim($request->getPath(), '/');
        if (strpos($path, 'api/imoveis/mapa') !== false) {
            return null;
        }

        // Determina identificador + limite a partir da identidade da requisição
        // (chave de API > token Shield > IP). Resolvido aqui de forma independente,
        // então funciona mesmo rodando ANTES do filtro de autenticação.
        [$identifier, $limit] = $this->resolve($request);

        // Sanitiza o identificador para evitar caracteres reservados no Cache (ex: IPv6 ::1)
        $identifier = str_replace([':', '{', '}', '(', ')', '/', '\\', '@'], '_', $identifier);

        // Usa cache para armazenar contadores
        $cache = \Config\Services::cache();
        $cacheKey = "rate_limit_{$identifier}";
        $windowKey = "rate_limit_window_{$identifier}";

        // Pega janela atual; se não existe ou expirou, abre uma nova e zera o
        // contador. Só a partir daqui o contador é incrementado de forma
        // atômica (increment() = HINCRBY no Redis), pra duas requisições
        // simultâneas (mesma identidade, possivelmente em instâncias
        // diferentes) não lerem o mesmo valor e "perderem" um incremento no
        // read-modify-write. increment() nunca deve ser seguido de get() na
        // mesma chave no handler Redis (increment() não grava o campo
        // __ci_type que get() exige) — por isso o save() abaixo sempre usa o
        // valor de retorno do próprio increment(), nunca um get() posterior.
        $now = time();
        $windowStart = $cache->get($windowKey);
        if (!$windowStart || ($now - $windowStart) >= 3600) {
            $cache->save($windowKey, $now, 3600);
            $cache->delete($cacheKey);
            $windowStart = $now;
        }

        $count = $cache->increment($cacheKey, 1);
        if ($count === false) {
            // Handler sem suporte a increment() (ex.: dummy) — cai pro
            // padrão não atômico anterior como fallback.
            $count = ($cache->get($cacheKey) ?? 0) + 1;
        }
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
     * Resolve [identificador, limite] a partir da identidade da requisição.
     *
     * Ordem de precedência:
     *  1. API Key (pk_...): bucket por chave, com o limite configurado na própria chave.
     *     Assim uma chave não escapa do limite trocando de IP, nem divide cota com
     *     outras contas atrás do mesmo IP.
     *  2. Token Shield (Bearer não-pk_): bucket estável por hash do token.
     *  3. Anônimo (rotas públicas): bucket por IP, limite baixo.
     *
     * @return array{0:string,1:int}
     */
    private function resolve(RequestInterface $request): array
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (preg_match('/Bearer\s+(pk_\S+)/i', $authHeader, $m)) {
            $apiKey = model(\App\Models\ApiKeyModel::class)->findByPlainKey($m[1]);
            if ($apiKey !== null) {
                $limit = (int) ($apiKey->rate_limit_per_hour ?: 1000);
                return ['key_' . $apiKey->id, $limit];
            }
            // Chave inválida: trata como anônimo (será barrado no api_auth de qualquer forma)
        } elseif (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
            // Token Shield: identidade estável por hash, sem precisar validar aqui.
            return ['tok_' . sha1($m[1]), 5000];
        }

        return ['ip_' . $request->getIPAddress(), 100];
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
