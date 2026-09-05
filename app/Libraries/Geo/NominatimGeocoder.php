<?php

namespace App\Libraries\Geo;

/**
 * Geocoder via Nominatim (OpenStreetMap), com a mesma escada de fallback que
 * o formulário de imóvel já usa no navegador (Properties/form.php,
 * função geocodeAddress): rua+número+bairro+cidade, depois rua+bairro+cidade,
 * depois bairro+cidade, e por fim só cidade+UF — cada consulta mais barata é a
 * rede de segurança da anterior.
 *
 * Fail-open de propósito: uma imobiliária com endereço mal formatado, ou o
 * Nominatim fora do ar, não pode derrubar o sync inteiro por causa de
 * coordenada — o imóvel entra sem lat/lng, e uma rodada futura tenta de novo.
 *
 * Cache de 30 dias por consulta normalizada: o endereço de um imóvel não muda
 * de um dia para o outro, e a política de uso do Nominatim pede no máximo
 * 1 requisição por segundo — cachear é o que faz uma rodada de centenas de
 * imóveis não virar centenas de segundos de espera.
 */
class NominatimGeocoder implements GeocoderInterface
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    private const CACHE_TTL_SECONDS = 60 * 60 * 24 * 30;

    /** Nominatim pede no máximo 1 req/s — aplicado só quando a consulta não veio do cache. */
    private const THROTTLE_MS = 1100;

    public function geocode(array $endereco): ?array
    {
        $cidade = trim((string) ($endereco['cidade'] ?? ''));

        if ($cidade === '') {
            return null;
        }

        foreach ($this->queries($endereco, $cidade) as $query) {
            $resultado = $this->lookup($query);

            if ($resultado !== null) {
                return $resultado;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function queries(array $endereco, string $cidade): array
    {
        $rua    = trim((string) ($endereco['rua'] ?? ''));
        $numero = trim((string) ($endereco['numero'] ?? ''));
        $bairro = trim((string) ($endereco['bairro'] ?? ''));

        $candidatas = [];

        if ($rua !== '' && $numero !== '' && $bairro !== '') {
            $candidatas[] = "{$rua} {$numero}, {$bairro}, {$cidade}, Brazil";
        }

        if ($rua !== '' && $bairro !== '') {
            $candidatas[] = "{$rua}, {$bairro}, {$cidade}, Brazil";
        }

        if ($bairro !== '') {
            $candidatas[] = "{$bairro}, {$cidade}, Brazil";
        }

        $candidatas[] = "{$cidade}, Brazil";

        // A mesma consulta pode se repetir entre os degraus (ex.: sem rua nem
        // bairro, os dois primeiros ficam vazios e sobra só a última) — sem
        // isso, um endereço incompleto bateria o Nominatim duas vezes com a
        // mesma string.
        return array_values(array_unique($candidatas));
    }

    /** @return array{lat:float, lng:float}|null */
    private function lookup(string $query): ?array
    {
        $cacheKey = 'geocode_nominatim_' . md5(mb_strtolower($query));
        $cached   = cache($cacheKey);

        if ($cached !== null) {
            // false = já consultamos essa string antes e o Nominatim não achou nada.
            return $cached === false ? null : $cached;
        }

        $resultado = $this->consultar($query);

        cache()->save($cacheKey, $resultado ?? false, self::CACHE_TTL_SECONDS);

        return $resultado;
    }

    /**
     * Faz a chamada de verdade. Protected e isolado numa função só pra
     * poder ser trocado por uma dublê roteirizada nos testes, sem tocar
     * em socket (mesmo padrão de IntegrationHttpClient::dispatch()).
     *
     * @return array{lat:float, lng:float}|null
     */
    protected function consultar(string $query): ?array
    {
        usleep(self::THROTTLE_MS * 1000);

        try {
            $client = \Config\Services::curlrequest([
                'timeout'     => 5,
                'http_errors' => false,
            ]);

            $response = $client->get(self::ENDPOINT, [
                'query'   => ['format' => 'json', 'q' => $query, 'limit' => 1],
                'headers' => [
                    // A política de uso do Nominatim exige um User-Agent que
                    // identifique a aplicação — IP sem identificação é banido.
                    'User-Agent' => 'Habitaweb-Integracoes/1.0',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $data = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            log_message('warning', '[NominatimGeocoder] Falha ao consultar: ' . $e->getMessage());

            return null;
        }

        if (! is_array($data) || ! isset($data[0]['lat'], $data[0]['lon'])) {
            return null;
        }

        return [
            'lat' => (float) $data[0]['lat'],
            'lng' => (float) $data[0]['lon'],
        ];
    }
}
