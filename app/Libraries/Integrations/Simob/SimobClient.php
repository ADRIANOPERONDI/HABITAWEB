<?php

namespace App\Libraries\Integrations\Simob;

use App\Libraries\Integrations\Exceptions\IntegrationException;
use App\Libraries\Integrations\Http\IntegrationHttpClient;

/**
 * Wrappers crus dos endpoints da API do Simob (Flexpro Sistemas).
 *
 * Documentação: https://documenter.getpostman.com/view/1724124/TVRecVa8
 *
 * DUAS ARMADILHAS que ditam o formato deste arquivo:
 *
 * 1. O Simob NÃO aceita corpo JSON. Todo POST é multipart/form-data com um
 *    único campo chamado `data`, contendo uma string JSON. Mandar
 *    ['json' => $payload] devolve erro de parâmetro ausente.
 *
 * 2. A base URL é POR IMOBILIÁRIA ({{url_imobiliaria}} no Postman) — cada
 *    cliente do Simob tem o próprio domínio. Por isso ela é credencial, e não
 *    constante.
 *
 * A API é SOMENTE LEITURA para imóveis: não existe endpoint para criar ou
 * alterar imóvel. O que ela aceita de volta é a criação de interesse no
 * SimobCRM, que é como os leads do Habitaweb voltam para a imobiliária.
 */
class SimobClient
{
    private const PREFIX = '/v2/integracaoApi';

    /** Finalidades do Simob. */
    public const FINALIDADE_LOCACAO = 1;
    public const FINALIDADE_VENDA   = 2;

    /**
     * Tamanho de página do catálogo.
     *
     * 50 é conservador de propósito: com quantidadeImagens = -1 cada item traz
     * o array completo de imagens, e páginas grandes viram respostas de vários
     * MB que estouram o timeout do lado de lá antes de chegar aqui.
     */
    public const PAGE_SIZE = 50;

    public function __construct(private IntegrationHttpClient $http)
    {
    }

    /**
     * Categorias de imóvel da imobiliária (Apartamento, Casa, Lote…).
     *
     * Finalidade > 2 devolve TODAS as categorias, e não só as da finalidade —
     * é o que se quer para semear o mapeamento. Também é a chamada mais barata
     * da API, então é ela que serve de teste de conexão.
     *
     * @return list<array{id:int|string, descricao:string}>
     */
    public function listCategories(int $finalidade = 3): array
    {
        $body = $this->http->get(self::PREFIX . '/imovel/categorias-imoveis/' . $finalidade, [
            'considerarPrevisaoSaida' => 'true',
        ]);

        return $this->unwrapList($body);
    }

    /**
     * Catálogo de características da imobiliária, com o tipo de cada uma.
     *
     * Os ids são criados por cada imobiliária: "Dormitório(s)" é 41 numa e 249
     * em outra. É por isso que o de/para é por tenant.
     *
     * @return list<array{id:int|string, descricao:string, idTipoCaracteristica:int}>
     */
    public function listCharacteristics(int $finalidade = 1): array
    {
        $body = $this->http->postMultipart(self::PREFIX . '/imovel/caracteristicas', [
            'data' => $this->encode([
                'finalidade'              => $finalidade,
                'idsCategorias'           => [],
                'ceps'                    => [],
                'idsBairros'              => [],
                'considerarPrevisaoSaida' => true,
            ]),
        ]);

        return $this->unwrapList($body);
    }

    /**
     * Uma página do catálogo, do mais recentemente atualizado para o mais antigo.
     *
     * A ordenação por 'atualizacao' desc é o que viabiliza o sync incremental:
     * a API não tem filtro updated_since, então o jeito de não varrer o
     * catálogo inteiro toda vez é parar de paginar quando aparece um imóvel
     * mais antigo que o último sync.
     *
     * @return list<array<string, mixed>>
     */
    public function listProperties(int $finalidade, int $firstResult, int $maxResults = self::PAGE_SIZE, int $trazerCaracteristicas = 50): array
    {
        $body = $this->http->postMultipart(self::PREFIX . '/imovel/filtro/categoria/caracteristicas', [
            'data' => $this->encode([
                'finalidade'              => $finalidade,
                'offset'                  => ['firstResult' => $firstResult, 'maxResults' => $maxResults],
                'orderBy'                 => [['sort' => 'atualizacao', 'order' => 'desc']],
                // -1 traz todas as imagens; sem isso vem só a principal e o
                // imóvel entra no Habitaweb com uma foto só.
                'quantidadeImagens'       => -1,
                'trazerCaracteristicas'   => $trazerCaracteristicas,
                'considerarPrevisaoSaida' => true,
                'trazerValorIptu'         => true,
            ]),
        ]);

        // O Simob devolve success:false tanto pra erro real (token, payload)
        // quanto pra "filtro sem resultado" — e uma imobiliária que só vende
        // (ou só aluga) bate nisso em toda página da finalidade oposta.
        // unwrapList() trataria isso como erro e abortaria o sync inteiro,
        // mesmo com a OUTRA finalidade tendo catálogo real pra importar.
        if ($this->isEmptyFilterResult($body)) {
            return [];
        }

        return $this->unwrapList($body);
    }

    /** Simob usa a mesma flag de erro para "nenhum imóvel para este filtro". */
    private function isEmptyFilterResult(array $body): bool
    {
        return ($body['success'] ?? null) === false
            && str_contains((string) ($body['message'] ?? ''), 'Nenhum imóvel encontrado');
    }

    /** Total de imóveis da finalidade, para saber quantas páginas existem. */
    public function countProperties(int $finalidade): int
    {
        $body = $this->http->postMultipart(self::PREFIX . '/imovel/filtro/categoria/caracteristicas', [
            'data' => $this->encode([
                'finalidade'              => $finalidade,
                'countResults'            => true,
                'considerarPrevisaoSaida' => true,
            ]),
        ]);

        $result = $body['result'] ?? 0;

        // A doc não fixa o formato do contador; já vi inteiro puro e objeto.
        if (is_array($result)) {
            $result = $result['count'] ?? $result['total'] ?? reset($result);
        }

        return (int) $result;
    }

    /**
     * Detalhe de um imóvel: descrição, todas as imagens, todas as
     * características, configVenda/configLocacao e IPTU.
     *
     * Recebe o CÓDIGO do imóvel (campo `codigo`), não o `id` — é o que a rota
     * espera, ainda que os dois costumem ser próximos.
     *
     * @return array<string, mixed>|null
     */
    public function getPropertyDetail(string $codigo): ?array
    {
        $body = $this->http->get(self::PREFIX . '/detalhes/imovel/' . rawurlencode($codigo), [
            'considerarPrevisaoSaida' => 'true',
            'calcularValorAbono'      => 'true',
        ]);

        $list = $this->unwrapList($body);

        return $list[0] ?? null;
    }

    /**
     * Cria interesse(s) no SimobCRM — é assim que o lead volta.
     *
     * O corpo é um ARRAY de interesses, mesmo quando é um só. Se a imobiliária
     * não tiver o módulo CRM, o Simob manda e-mail no lugar, usando o objeto
     * `config` de cada interesse.
     *
     * @param list<array<string, mixed>> $interesses
     *
     * @return array<string, mixed>
     */
    public function createInterest(array $interesses): array
    {
        if ($interesses === []) {
            throw new IntegrationException('Nenhum interesse para enviar.');
        }

        return $this->http->postMultipart(self::PREFIX . '/crm_interesse/create', [
            'data' => $this->encode(array_values($interesses)),
        ]);
    }

    /**
     * URL pública de uma imagem.
     *
     * A doc do Postman descreve um endpoint `/cdn/imovelImages/{id}/...` como
     * substituto do campo `baseUrlImagem`, que ela marca como depreciado —
     * mas contra uma instalação real (imobiliária Giusti, base_url própria,
     * não subdomínio *.simob.com.br) o `/cdn/` devolve 404 e o caminho de
     * `baseUrlImagem` devolve a imagem de verdade (confirmado com curl direto
     * nos dois). Não dá pra saber se o CDN existe noutras instalações, mas
     * `baseUrlImagem` é o campo que a própria API devolve pra esse fim, então
     * é a fonte confiável — não uma string montada por fora. A URL segue
     * estável entre rodadas — PropertyService::addMediaFromUrl deduplica por
     * sha256(url), e uma URL volátil faria o sync rebaixar todas as fotos
     * toda vez.
     */
    public static function imageUrl(string $baseUrl, string $baseUrlImagem, string $baseNomeImagem, string $extensao): string
    {
        $segmentos = array_map(
            'rawurlencode',
            array_filter(explode('/', trim($baseUrlImagem, '/')), static fn (string $s): bool => $s !== '')
        );

        return sprintf(
            '%s/%s/%s.%s',
            rtrim($baseUrl, '/'),
            implode('/', $segmentos),
            rawurlencode($baseNomeImagem),
            ltrim($extensao, '.')
        );
    }

    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Desembrulha o envelope {success, result} da API.
     *
     * success = false com mensagem vira exception: senão o sync trataria a
     * resposta de erro como "catálogo vazio" e pausaria todos os imóveis do
     * tenant por engano.
     *
     * @return list<array<string, mixed>>
     */
    private function unwrapList(array $body): array
    {
        if (array_key_exists('success', $body) && $body['success'] === false) {
            $message = is_string($body['message'] ?? null) ? $body['message'] : 'sem detalhe';

            throw new IntegrationException('O Simob recusou a consulta: ' . $message);
        }

        $result = $body['result'] ?? [];

        if (! is_array($result)) {
            return [];
        }

        // Endpoint de detalhe devolve lista; alguns devolvem objeto único.
        if ($result !== [] && ! array_is_list($result)) {
            return [$result];
        }

        return $result;
    }
}
