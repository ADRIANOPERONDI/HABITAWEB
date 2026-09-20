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
 * O degrau do bairro só aceita resultado que SEJA um lugar (class place ou
 * boundary). Sem esse filtro ele pegava o primeiro casamento de texto livre,
 * que para "Centro, São Miguel do Oeste, SC" é a Epagri Cetresmo — um órgão
 * público na SC-163, a 7 km do centro: 36 imóveis do catálogo de um cliente
 * foram parar lá. Bairro que existe no OSM volta como class=place/type=suburb
 * (Salete, por exemplo); quando não existe, o certo é descer pro degrau da
 * cidade e usar o centro administrativo, e não fixar num ponto comercial
 * qualquer que por acaso tem a palavra no nome.
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
    private const CACHE_VERSION = 'v3';

    /**
     * O que conta como "lugar" no degrau do bairro. `place` cobre suburb,
     * neighbourhood, quarter e village; `boundary` cobre bairro mapeado como
     * área administrativa. Escola, posto de saúde e autoescola que tenham a
     * palavra no nome vêm como `amenity`/`office` e ficam de fora.
     */
    private const CLASSES_DE_LUGAR = ['place', 'boundary'];

    /** Quantos resultados pedir quando há filtro — o primeiro pode não servir. */
    private const CANDIDATOS_COM_FILTRO = 5;

    /**
     * Onde o nome do logradouro acaba e começa a descrição livre. O Simob
     * grava a esquina e o apartamento dentro do campo de rua — "RUA LA SALLE,
     * ESQUINA COM A RUA MARQUES DO HERVAL - APTO 2301 | BOX 41, 42 E 43" —, e
     * o Nominatim não resolve nada disso: 54 imóveis desciam a escada inteira
     * e paravam no centro da cidade. Cortado em "RUA LA SALLE", resolve.
     */
    private const FIM_DO_LOGRADOURO = [
        '/\bESQUINA\b/iu',
        '/\bCOM\s+(?:A\s+)?(?:RUAS?|AVENIDAS?|AV\.?)\b/iu',
        '/\s[-–—]\s/u',
        '/\|/u',
        '/\bN[º°]\b/iu',
    ];

    /** Nominatim pede no máximo 1 req/s — aplicado só quando a consulta não veio do cache. */
    private const THROTTLE_MS = 1100;

    public function geocode(array $endereco): ?array
    {
        $cidade = trim((string) ($endereco['cidade'] ?? ''));

        if ($cidade === '') {
            return null;
        }

        foreach ($this->queries($endereco, $cidade) as $candidato) {
            $resultado = $this->lookup($candidato['params'], $candidato['aceita']);

            if ($resultado !== null) {
                return $resultado;
            }
        }

        return null;
    }

    /**
     * Os degraus, do mais específico pro mais genérico.
     *
     * `aceita` restringe quais classes do Nominatim servem naquele degrau;
     * null = qualquer resultado serve.
     *
     * @return list<array{params: array<string, string>, aceita: list<string>|null}>
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
            $candidatas[] = ['params' => $local + ['street' => "{$rua} {$numero}"], 'aceita' => null];
        }

        if ($rua !== '') {
            $candidatas[] = ['params' => $local + ['street' => $rua], 'aceita' => null];
        }

        // Os mesmos dois degraus com o logradouro isolado da descrição. Vêm
        // DEPOIS do texto original de propósito: quando o original resolve,
        // ele é mais específico e estes nem chegam a ser consultados; o dedupe
        // abaixo descarta quando a limpeza não mudou nada.
        $ruaLimpa = $this->isolarLogradouro($rua);

        if ($ruaLimpa !== '' && $ruaLimpa !== $rua) {
            if ($numero !== '') {
                $candidatas[] = ['params' => $local + ['street' => "{$ruaLimpa} {$numero}"], 'aceita' => null];
            }

            $candidatas[] = ['params' => $local + ['street' => $ruaLimpa], 'aceita' => null];
        }

        if ($bairro !== '') {
            $cidadeUf     = $estado !== '' ? "{$cidade}, {$estado}" : $cidade;
            // Só bairro de verdade: sem isto o texto livre casava com a
            // primeira empresa que tivesse a palavra no nome.
            $candidatas[] = ['params' => ['q' => "{$bairro}, {$cidadeUf}, Brazil"], 'aceita' => self::CLASSES_DE_LUGAR];
        }

        $candidatas[] = ['params' => $local, 'aceita' => null];

        // Um endereço incompleto pode repetir o mesmo conjunto de parâmetros
        // entre degraus — sem isso, bateríamos o Nominatim duas vezes com a
        // consulta idêntica.
        $unicas = [];

        foreach ($candidatas as $candidato) {
            $unicas[$this->cacheSubject($candidato['params'])] = $candidato;
        }

        return array_values($unicas);
    }

    /**
     * Fica só com o nome do logradouro, jogando fora a descrição que o
     * cadastro de origem enfia no mesmo campo (esquina, apartamento, box,
     * torre, lote). "ESQUINA DAS RUAS MARCÍLIO DIAS COM RUA ALMIRANTE
     * BARROSO - APTO 604" vira "MARCÍLIO DIAS", que o Nominatim resolve.
     *
     * Devolve string vazia quando não sobra nada — quem chama ignora.
     */
    protected function isolarLogradouro(string $rua): string
    {
        $rua = trim(preg_replace('/\s+/u', ' ', $rua) ?? '');

        if ($rua === '') {
            return '';
        }

        // "ESQUINA DAS RUAS X COM Y": o nome começa depois do prefixo.
        $rua = preg_replace('/^ESQUINA\s+(?:D[AEO]S?\s+)?(?:RUAS?|AVENIDAS?|AV\.?)\s+/iu', '', $rua) ?? $rua;

        $corte = mb_strlen($rua);

        foreach (self::FIM_DO_LOGRADOURO as $padrao) {
            if (preg_match($padrao, $rua, $m, PREG_OFFSET_CAPTURE)) {
                // preg devolve offset em BYTES; mb_substr conta CARACTERES.
                $pos = mb_strlen(substr($rua, 0, $m[0][1]));

                if ($pos < $corte) {
                    $corte = $pos;
                }
            }
        }

        return trim(mb_substr($rua, 0, $corte), " \t\n\r\0\x0B,.;-–—|");
    }

    /**
     * @param array<string, string> $params
     * @param list<string>|null     $aceita classes do Nominatim que servem; null = qualquer uma
     *
     * @return array{lat:float, lng:float}|null
     */
    private function lookup(array $params, ?array $aceita = null): ?array
    {
        // O filtro entra na chave: a mesma consulta com e sem restricao de
        // classe pode legitimamente dar respostas diferentes.
        $assinatura = $this->cacheSubject($params) . '|' . implode(',', $aceita ?? []);
        $cacheKey   = 'geocode_nominatim_' . self::CACHE_VERSION . '_' . md5($assinatura);
        $cached   = cache($cacheKey);

        if ($cached !== null) {
            // false = já consultamos essa string antes e o Nominatim não achou nada.
            return $cached === false ? null : $cached;
        }

        $resultado = $this->consultar($params, $aceita);

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
     * @param list<string>|null     $aceita classes do Nominatim que servem; null = qualquer uma
     *
     * @return array{lat:float, lng:float}|null
     */
    protected function consultar(array $params, ?array $aceita = null): ?array
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
                    // Com filtro o primeiro resultado pode nao servir; sem
                    // filtro um so basta e a resposta e menor.
                    'limit' => $aceita === null ? 1 : self::CANDIDATOS_COM_FILTRO,
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

        return $this->selecionar($data, $aceita);
    }

    /**
     * Primeiro resultado que serve. Separado de consultar() pra poder ser
     * testado sem rede — e porque a regra aqui é a que impede um órgão público
     * de virar o centro de um bairro.
     *
     * @param mixed            $data   corpo já decodificado da resposta
     * @param list<string>|null $aceita classes que servem; null = qualquer uma
     *
     * @return array{lat:float, lng:float}|null null faz quem chamou descer pro próximo degrau
     */
    protected function selecionar($data, ?array $aceita): ?array
    {
        if (! is_array($data)) {
            return null;
        }

        foreach ($data as $item) {
            if (! is_array($item) || ! isset($item['lat'], $item['lon'])) {
                continue;
            }

            if ($aceita !== null && ! in_array($item['class'] ?? '', $aceita, true)) {
                continue;
            }

            return [
                'lat' => (float) $item['lat'],
                'lng' => (float) $item['lon'],
            ];
        }

        return null;
    }
}
