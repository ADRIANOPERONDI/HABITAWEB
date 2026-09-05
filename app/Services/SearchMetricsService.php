<?php

namespace App\Services;

use App\Libraries\Metrics\PriceBuckets;
use App\Libraries\Metrics\RedisMetricsBuffer;

/**
 * Captura de busca para `search_daily` (Fase 4) — chamado de
 * `MapSearchController::getMapData`, o endpoint que serve as buscas reais.
 *
 * Três guardas obrigatórias, nesta ordem (a mais barata primeiro):
 *   1. sem filtro semântico nenhum → nem é busca, é "mostra tudo".
 *   2. bot → não é demanda real.
 *   3. dedup de pan/zoom do mapa (60s, por IP) → sem isso um único visitante
 *      indeciso arrastando o mapa definiria sozinho o "bairro mais
 *      procurado do mês".
 */
class SearchMetricsService
{
    private const BOT_SIGNATURES = [
        'bot', 'spider', 'crawl', 'slurp', 'curl/', 'wget/',
        'python-requests', 'scrapy', 'headlesschrome', 'phantomjs',
    ];

    /** Chaves de filtro que indicam intenção de busca real. `bounds` fica de fora de propósito: viewport do mapa sozinho não é busca. */
    private const SEMANTIC_KEYS = [
        'tipo_negocio', 'cidade', 'bairro', 'tipo_imovel',
        'quartos', 'banheiros', 'vagas', 'min_price', 'max_price', 'polygon',
    ];

    public function __construct(private ?RedisMetricsBuffer $buffer = null)
    {
        $this->buffer ??= service('metricsBuffer');
    }

    /**
     * `$ip`/`$userAgent` são opcionais e existem para teste determinístico —
     * por padrão vêm da requisição corrente. `MapSearchController` sempre
     * usa o default.
     */
    public function record(array $filters, ?string $ip = null, ?string $userAgent = null): void
    {
        if (! $this->hasSemanticFilter($filters)) {
            return;
        }

        if ($ip === null || $userAgent === null) {
            $request    = service('request');
            $ip       ??= $request->getIPAddress();
            $userAgent ??= $request instanceof \CodeIgniter\HTTP\IncomingRequest ? (string) $request->getUserAgent() : '';
        }

        if ($this->isBot($userAgent)) {
            return;
        }

        $tipoNegocio = (string) ($filters['tipo_negocio'] ?? '');
        $cidade      = (string) ($filters['cidade'] ?? '');
        $bairro      = (string) ($filters['bairro'] ?? '');
        $tipoImovel  = (string) ($filters['tipo_imovel'] ?? '');
        $faixaPreco  = PriceBuckets::bucketForSearch($tipoNegocio, $filters['min_price'] ?? null, $filters['max_price'] ?? null);

        // Dedup por IP, não global: dois visitantes diferentes buscando a
        // mesma coisa ao mesmo tempo são duas buscas de verdade. Um só
        // arrastando o mapa (bounds muda, o resto não) é uma só.
        $dedupDims = [
            $tipoNegocio, $cidade, $bairro, $tipoImovel,
            $filters['quartos'] ?? '', $filters['banheiros'] ?? '', $filters['vagas'] ?? '',
            $filters['min_price'] ?? '', $filters['max_price'] ?? '',
        ];
        $dedupKey = 'hw:metrics:search:seen:' . sha1($ip . '|' . implode('|', $dedupDims));

        if (! $this->buffer->markSeenOnce($dedupKey, 60)) {
            return;
        }

        $this->buffer->bufferSearch(date('Y-m-d'), [$tipoNegocio, $cidade, $bairro, $tipoImovel, $faixaPreco]);
    }

    private function hasSemanticFilter(array $filters): bool
    {
        foreach (self::SEMANTIC_KEYS as $key) {
            if (! empty($filters[$key])) {
                return true;
            }
        }

        return false;
    }

    private function isBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        $ua = mb_strtolower($userAgent);

        foreach (self::BOT_SIGNATURES as $signature) {
            if (str_contains($ua, $signature)) {
                return true;
            }
        }

        return false;
    }
}
