<?php

namespace App\Libraries\Geo;

/**
 * Geocoder via Nominatim (OpenStreetMap), com a mesma escada de fallback que
 * o formulário de imóvel já usa no navegador (Properties/form.php,
 * função geocodeAddress): rua+número, depois rua, depois bairro, e por fim só
 * cidade — cada consulta mais barata é a rede de segurança da anterior.
 *
 * A UF entra em TODOS os degraus, e isso não é detalhe: sem ela o Nominatim
 * resolvia "Centro, São Miguel do Oeste" como São Miguel do Araguaia, em
 * Goiás, a 1.500 km do imóvel. Metade do catálogo de um cliente foi parar no
 * Centro-Oeste desse jeito.
 *
 * Os degraus de rua e de cidade usam a consulta ESTRUTURADA do Nominatim
 * (street/city/state), não texto livre: ela casa a rua em endereços que o
 * texto livre devolvia vazio — e quem devolve vazio cai pro degrau seguinte e
 * acaba no centro da cidade, que é justamente o que empilha dezenas de pins no
 * mesmo ponto. O degrau de bairro fica em texto livre porque o Nominatim não
 * tem parâmetro estruturado de bairro (street/city/county/state/postalcode) e
 * enfiar o bairro em `street` não acha nada.
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

    /**
     * Entra na chave do cache. Mudar de versão aposenta de uma vez todas as
     * respostas já gravadas — foi o que aposentou as coordenadas de Goiás,
     * que ficariam 30 dias em cache respondendo pelo formato antigo de
     * consulta mesmo depois do conserto.
     */
    private const CACHE_VERSION = 'v2';

    /** Nominatim pede no máximo 1 req/s — aplicado só quando a consulta não veio do cache. */
    private const THROTTLE_MS = 1100;

    public function geocode(array $endereco): ?array
    {
        $cidade = trim((string) ($endereco['cidade'] ?? ''));

        if ($cidade === '') {
            return null;
        }

        foreach ($this->queries($endereco, $cidade) as $params) {
            $resultado = $this->lookup($params);

            if ($resultado !== null) {
                return $resultado;
            }
        }

        return null;
    }

    /**
     * Os degraus, do mais específico pro mais genérico.
     *
     * @return list<array<string, string>> cada item é o conjunto de parâmetros de uma consulta
     */
    private function queries(array $endereco, string $cidade): array
    {
        $rua    = trim((string) ($endereco['rua'] ?? ''));
        $numero = trim((string) ($endereco['numero'] ?? ''));
        $bairro = trim((string) ($endereco['bairro'] ?? ''));
        $estado = trim((string) ($endereco['estado'] ?? ''));

        // Cidade + UF, o par que amarra todos os degraus no município certo.
        $local = ['city' => $cidade];

        if ($estado !== '') {
            $local['state'] = $estado;
        }

        $candidatas = [];

        if ($rua !== '' && $numero !== '') {
            $candidatas[] = $local + ['street' => "{$rua} {$numero}"];
        }

        if ($rua !== '') {
            $candidatas[] = $local + ['street' => $rua];
        }

        if ($bairro !== '') {
            $cidadeUf     = $estado !== '' ? "{$cidade}, {$estado}" : $cidade;
            $candidatas[] = ['q' => "{$bairro}, {$cidadeUf}, Brazil"];
        }

        $candidatas[] = $local;

        // Um endereço incompleto pode repetir o mesmo conjunto de parâmetros
        // entre degraus — sem isso, bateríamos o Nominatim duas vezes com a
        // consulta idêntica.
        $unicas = [];

        foreach ($candidatas as $params) {
            $unicas[$this->cacheSubject($params)] = $params;
        }

        return array_values($unicas);
    }

    /**
     * @param array<string, string> $params
     *
     * @return array{lat:float, lng:float}|null
     */
    private function lookup(array $params): ?array
    {
        $cacheKey = 'geocode_nominatim_' . self::CACHE_VERSION . '_' . md5($this->cacheSubject($params));
        $cached   = cache($cacheKey);

        if ($cached !== null) {
            // false = já consultamos essa string antes e o Nominatim não achou nada.
            return $cached === false ? null : $cached;
        }

        $resultado = $this->consultar($params);

        cache()->save($cacheKey, $resultado ?? false, self::CACHE_TTL_SECONDS);

        return $resultado;
    }

    /**
     * Forma canônica de um conjunto de parâmetros: ordem de chave e caixa não
     * podem gerar duas entradas de cache para a mesma consulta.
     *
     * @param array<string, string> $params
     */
    private function cacheSubject(array $params): string
    {
        $normalizados = array_map(
            static fn (string $valor): string => mb_strtolower(trim($valor)),
            $params
        );

        ksort($normalizados);

        return json_encode($normalizados, JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * Faz a chamada de verdade. Protected e isolado numa função só pra
     * poder ser trocado por uma dublê roteirizada nos testes, sem tocar
     * em socket (mesmo padrão de IntegrationHttpClient::dispatch()).
     *
     * @param array<string, string> $params
     *
     * @return array{lat:float, lng:float}|null
     */
    protected function consultar(array $params): ?array
    {
        usleep(self::THROTTLE_MS * 1000);

        try {
            $client = \Config\Services::curlrequest([
                'timeout'     => 5,
                'http_errors' => false,
            ]);

            $response = $client->get(self::ENDPOINT, [
                'query' => $params + [
                    'format' => 'json',
                    'limit'  => 1,
                    // Trava o resultado no Brasil: sem isso um nome de cidade
                    // brasileiro casa com homônimo em Portugal.
                    'countrycodes' => 'br',
                ],
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
