<?php

namespace App\Libraries\Integrations\Http;

use App\Libraries\Http\UrlGuard;
use App\Libraries\Integrations\Exceptions\AuthException;
use App\Libraries\Integrations\Exceptions\IntegrationException;
use App\Libraries\Integrations\Exceptions\RateLimitException;

/**
 * Cliente HTTP dos conectores de integração.
 *
 * Os gateways de pagamento montam o curlrequest na mão em cada classe
 * (AsaasGateway::request e companhia). Funciona, mas não tem timeout explícito,
 * não tem retry e loga sem critério. Aqui isso é centralizado porque a
 * diferença importa mais: sincronizar um catálogo são centenas de chamadas
 * seguidas contra um servidor de terceiro que pode estar lento, e um único 502
 * no meio não pode derrubar a rodada inteira.
 *
 * Três garantias:
 *
 * 1. A base URL é do TENANT (ele digita no painel), então passa pelo UrlGuard
 *    antes da primeira chamada — sem isso a tela de integrações vira um SSRF
 *    de mão beijada contra o metadata da nuvem.
 * 2. Retry com backoff exponencial em 429 e 5xx, e só nesses. 4xx é erro de
 *    programação ou de credencial: repetir não conserta e ainda queima quota.
 * 3. Log nunca inclui token, header de autorização nem corpo de resposta — o
 *    corpo de um imóvel traz dados de proprietário e inquilino (nome,
 *    telefone, e-mail), como se vê no endpoint "Dados Imóvel" do Simob.
 */
class IntegrationHttpClient
{
    /** Tentativas totais (1 original + 2 repetições). */
    public const MAX_ATTEMPTS = 3;

    /** Base do backoff exponencial, em milissegundos: 500ms, 1s, 2s… */
    private const BACKOFF_BASE_MS = 500;

    private const TIMEOUT         = 20;
    private const CONNECT_TIMEOUT = 8;

    private string $baseUrl;
    private array $defaultHeaders;
    private bool $baseUrlValidated = false;
    private string $logPrefix;

    public function __construct(string $baseUrl, array $defaultHeaders = [], string $logPrefix = 'Integration')
    {
        $this->baseUrl        = rtrim(trim($baseUrl), '/');
        $this->defaultHeaders = $defaultHeaders;
        $this->logPrefix      = $logPrefix;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<mixed> Corpo da resposta já decodificado
     */
    public function get(string $endpoint, array $query = []): array
    {
        $options = [];

        if ($query !== []) {
            $options['query'] = array_filter($query, static fn ($v) => $v !== null);
        }

        return $this->send('GET', $endpoint, $options);
    }

    /**
     * POST em multipart/form-data.
     *
     * É o formato que o Simob exige: um único campo `data` com uma string JSON
     * dentro. Mandar ['json' => $payload] devolve erro de parâmetro ausente.
     *
     * @param array<string, string> $fields
     *
     * @return array<mixed>
     */
    public function postMultipart(string $endpoint, array $fields): array
    {
        // CURLRequest repassa `multipart` direto para CURLOPT_POSTFIELDS sem
        // nenhum tratamento (ver CURLRequest::applyBody) — não existe o shim
        // estilo Guzzle que interpretaria [['name'=>.., 'contents'=>..], ...].
        // Passar isso faz o cURL nativo tratar os índices numéricos como nome
        // de campo e achatar 'name'/'contents' em colchetes (`0[name]`,
        // `0[contents]`), então o campo `data` que a origem exige nunca chega
        // com esse nome. Precisa ser um array associativo simples
        // campo => valor, que é o que o cURL nativo espera.
        return $this->send('POST', $endpoint, ['multipart' => $fields]);
    }

    /**
     * POST com corpo JSON, para conectores que aceitem.
     *
     * @return array<mixed>
     */
    public function postJson(string $endpoint, array $payload): array
    {
        return $this->send('POST', $endpoint, ['json' => $payload]);
    }

    /**
     * @return array<mixed>
     *
     * @throws AuthException      401/403
     * @throws RateLimitException 429 depois de esgotar as tentativas
     * @throws IntegrationException demais falhas
     */
    private function send(string $method, string $endpoint, array $options): array
    {
        $this->assertBaseUrlIsSafe();

        $url      = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $attempt  = 0;
        $lastFail = null;

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;

            try {
                $response = $this->dispatch($method, $url, $options);
            } catch (\Throwable $e) {
                // Timeout, DNS, TLS: vale repetir.
                $lastFail = new IntegrationException(
                    'Não foi possível contatar a plataforma externa. Verifique a URL e tente novamente.'
                );
                log_message('warning', sprintf(
                    '[%s] Falha de transporte em %s %s (tentativa %d/%d): %s',
                    $this->logPrefix,
                    $method,
                    $this->redactUrl($url),
                    $attempt,
                    self::MAX_ATTEMPTS,
                    $e->getMessage()
                ));

                $this->backoff($attempt);

                continue;
            }

            $status = $response->getStatusCode();
            $body   = (string) $response->getBody();

            if ($status === 401 || $status === 403) {
                throw new AuthException(
                    'Credencial recusada pela plataforma externa. Confira o token de integração.'
                );
            }

            if ($status === 429) {
                $retryAfter = (int) ($response->getHeaderLine('Retry-After') ?: 0);
                $lastFail   = new RateLimitException(retryAfter: $retryAfter ?: null);

                log_message('warning', sprintf(
                    '[%s] 429 em %s (tentativa %d/%d)',
                    $this->logPrefix,
                    $this->redactUrl($url),
                    $attempt,
                    self::MAX_ATTEMPTS
                ));

                $this->backoff($attempt, $retryAfter);

                continue;
            }

            if ($status >= 500) {
                $lastFail = new IntegrationException(
                    "A plataforma externa respondeu com erro {$status}. Tente novamente em alguns minutos."
                );

                log_message('warning', sprintf(
                    '[%s] HTTP %d em %s (tentativa %d/%d)',
                    $this->logPrefix,
                    $status,
                    $this->redactUrl($url),
                    $attempt,
                    self::MAX_ATTEMPTS
                ));

                $this->backoff($attempt);

                continue;
            }

            if ($status >= 400) {
                // 4xx não é repetível: ou o parâmetro está errado, ou o recurso
                // não existe. Repetir só queima quota.
                log_message('error', sprintf(
                    '[%s] HTTP %d em %s',
                    $this->logPrefix,
                    $status,
                    $this->redactUrl($url)
                ));

                throw new IntegrationException(
                    "A plataforma externa recusou a requisição (HTTP {$status})."
                );
            }

            return $this->decode($body, $url);
        }

        throw $lastFail ?? new IntegrationException('Falha ao contatar a plataforma externa.');
    }

    /** Isolado para poder ser substituído nos testes sem tocar em rede. */
    protected function dispatch(string $method, string $url, array $options)
    {
        $client = \Config\Services::curlrequest([
            'timeout'         => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'http_errors'     => false,
            'verify'          => true,
        ]);

        $options['headers'] = array_merge([
            'Accept'     => 'application/json',
            'User-Agent' => 'Habitaweb-Integracoes/1.0',
        ], $this->defaultHeaders, $options['headers'] ?? []);

        return $client->request($method, $url, $options);
    }

    /** @return array<mixed> */
    private function decode(string $body, string $url): array
    {
        if (trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            log_message('error', sprintf(
                '[%s] Resposta não-JSON em %s (%d bytes)',
                $this->logPrefix,
                $this->redactUrl($url),
                strlen($body)
            ));

            throw new IntegrationException(
                'A plataforma externa devolveu uma resposta em formato inesperado. '
                . 'Confira se a URL aponta para o sistema certo.'
            );
        }

        return $decoded;
    }

    /**
     * A URL vem do tenant — sem esta checagem, digitar http://169.254.169.254
     * no painel faria o servidor buscar as credenciais da instância.
     */
    private function assertBaseUrlIsSafe(): void
    {
        if ($this->baseUrlValidated) {
            return;
        }

        if ($this->baseUrl === '') {
            throw new IntegrationException('URL da plataforma externa não configurada.');
        }

        // UrlGuard aceita http e https de propósito: ele também protege a
        // target_url de webhook e a URL de imagem do import, onde exigir
        // https quebraria integrações legítimas que ainda servem por http.
        // Uma credencial de integração (token, aqui) trafegando em texto
        // puro por http é um risco de outra categoria — a chave vaza pra
        // qualquer um no caminho da rede —, e essa exigência é só desta
        // classe, não do guard genérico.
        if (ENVIRONMENT !== 'development' && ! str_starts_with(strtolower($this->baseUrl), 'https://')) {
            throw new IntegrationException(
                'URL da plataforma externa inválida: apenas conexões https são aceitas.'
            );
        }

        $check = (new UrlGuard())->validate($this->baseUrl);

        if (! ($check['valid'] ?? false)) {
            throw new IntegrationException(
                'URL da plataforma externa inválida: ' . ($check['message'] ?? 'endereço não permitido') . '.'
            );
        }

        $this->baseUrlValidated = true;
    }

    /** Espera entre tentativas. Isolado para os testes poderem anular. */
    protected function backoff(int $attempt, int $retryAfterSeconds = 0): void
    {
        if ($attempt >= self::MAX_ATTEMPTS) {
            return;
        }

        if ($retryAfterSeconds > 0) {
            // Respeita o pedido do servidor, com teto para não travar o worker.
            usleep(min($retryAfterSeconds, 10) * 1_000_000);

            return;
        }

        usleep(self::BACKOFF_BASE_MS * (2 ** ($attempt - 1)) * 1000);
    }

    /** Tira query string do log: pode carregar token em conectores que autenticam por URL. */
    private function redactUrl(string $url): string
    {
        return strtok($url, '?') ?: $url;
    }
}
