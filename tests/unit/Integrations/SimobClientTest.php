<?php

namespace Tests\Unit\Integrations;

use App\Libraries\Integrations\Exceptions\IntegrationException;
use App\Libraries\Integrations\Http\IntegrationHttpClient;
use App\Libraries\Integrations\Simob\SimobClient;
use PHPUnit\Framework\TestCase;

/**
 * O SimobClient de verdade, contra um IntegrationHttpClient dublê.
 *
 * Aqui se prova a armadilha nº 1 da API do Simob: ela não aceita corpo JSON.
 * Todo POST é multipart/form-data com um único campo `data` contendo uma
 * string JSON. Um refactor que trocasse isso por ['json' => ...] passaria em
 * todos os outros testes e quebraria em produção.
 *
 * @internal
 */
final class SimobClientTest extends TestCase
{
    private function client(array $responses): array
    {
        $http   = new RecordingHttpClient('https://203.0.113.10', $responses);
        $client = new SimobClient($http);

        return [$client, $http];
    }

    public function testListagemVaiComoMultipartNoCampoData(): void
    {
        [$client, $http] = $this->client([['success' => true, 'result' => []]]);

        $client->listProperties(SimobClient::FINALIDADE_LOCACAO, 100);

        $call = $http->calls[0];

        $this->assertSame('postMultipart', $call['method']);
        $this->assertSame('/v2/integracaoApi/imovel/filtro/categoria/caracteristicas', $call['endpoint']);
        $this->assertArrayHasKey('data', $call['fields'], 'o payload vai num campo chamado data');

        $payload = json_decode($call['fields']['data'], true);

        $this->assertSame(1, $payload['finalidade']);
        $this->assertSame(['firstResult' => 100, 'maxResults' => 50], $payload['offset']);
        $this->assertSame([['sort' => 'atualizacao', 'order' => 'desc']], $payload['orderBy']);
        $this->assertSame(-1, $payload['quantidadeImagens'], '-1 traz todas as imagens');
    }

    public function testTesteDeConexaoUsaGetDeCategorias(): void
    {
        [$client, $http] = $this->client([['success' => true, 'result' => [['id' => 1, 'descricao' => 'Casa']]]]);

        $categorias = $client->listCategories(3);

        $this->assertSame('get', $http->calls[0]['method']);
        $this->assertSame('/v2/integracaoApi/imovel/categorias-imoveis/3', $http->calls[0]['endpoint']);
        $this->assertCount(1, $categorias);
    }

    public function testDetalheDesembrulhaOPrimeiroItemDaLista(): void
    {
        [$client] = $this->client([[
            'success' => true,
            'result'  => [['id' => 3376, 'codigo' => '3364']],
        ]]);

        $detail = $client->getPropertyDetail('3364');

        $this->assertSame(3376, $detail['id']);
    }

    public function testResultObjetoUnicoViraListaDeUm(): void
    {
        [$client] = $this->client([[
            'success' => true,
            'result'  => ['codigo' => '1883', 'tipoImovel' => 2],
        ]]);

        $detail = $client->getPropertyDetail('1883');

        $this->assertSame('1883', $detail['codigo']);
    }

    /**
     * Tratar success=false como catálogo vazio faria o sync pausar TODOS os
     * imóveis do tenant, achando que sumiram da origem.
     */
    public function testSuccessFalseViraExceptionEmVezDeListaVazia(): void
    {
        [$client] = $this->client([['success' => false, 'message' => 'token expirado']]);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('token expirado');

        $client->listProperties(1, 0);
    }

    /**
     * O Simob usa a MESMA flag success=false pra "filtro sem resultado" (uma
     * imobiliária que só vende bate nisso em toda página da finalidade de
     * locação) — sem essa distinção, uma imobiliária sem nenhum imóvel numa
     * das duas finalidades nunca conseguiria sincronizar a outra.
     */
    public function testNenhumImovelEncontradoViraListaVaziaEmVezDeException(): void
    {
        [$client] = $this->client([['success' => false, 'message' => 'Nenhum imóvel encontrado para este filtro!']]);

        $this->assertSame([], $client->listProperties(1, 0));
    }

    public function testContadorAceitaInteiroPuro(): void
    {
        [$client, $http] = $this->client([['success' => true, 'result' => 137]]);

        $this->assertSame(137, $client->countProperties(1));
        $this->assertTrue(json_decode($http->calls[0]['fields']['data'], true)['countResults']);
    }

    public function testContadorAceitaObjeto(): void
    {
        [$client] = $this->client([['success' => true, 'result' => ['count' => 42]]]);

        $this->assertSame(42, $client->countProperties(2));
    }

    public function testInteresseVaiComoArrayMesmoSendoUmSo(): void
    {
        [$client, $http] = $this->client([['success' => true]]);

        $client->createInterest([['nome' => 'Ana']]);

        $payload = json_decode($http->calls[0]['fields']['data'], true);

        $this->assertTrue(array_is_list($payload), 'o Simob espera um array de interesses');
        $this->assertSame('Ana', $payload[0]['nome']);
    }

    public function testInteresseVazioNaoSai(): void
    {
        [$client, $http] = $this->client([]);

        $this->expectException(IntegrationException::class);

        try {
            $client->createInterest([]);
        } finally {
            $this->assertSame([], $http->calls);
        }
    }

    /** Acento em bairro e cidade tem que sair legível, não como ç. */
    public function testPayloadNaoEscapaAcentuacao(): void
    {
        [$client, $http] = $this->client([['success' => true]]);

        $client->createInterest([[
            'nome'   => 'João',
            'cidade' => 'Chapecó',
            'config' => ['urlImovel' => 'https://habitaweb.com.br/imoveis/1'],
        ]]);

        $data = $http->calls[0]['fields']['data'];

        // Se JSON_UNESCAPED_UNICODE estivesse desligado, o nome sairia como
        // "Jo\u00e3o" e estas duas buscas literais falhariam.
        $this->assertStringContainsString('João', $data);
        $this->assertStringContainsString('Chapecó', $data);

        // JSON_UNESCAPED_SLASHES: a URL do imóvel não pode virar https:\/\/...
        $this->assertStringContainsString('https://habitaweb.com.br/imoveis/1', $data);
    }
}

/** Registra as chamadas em vez de fazê-las. */
final class RecordingHttpClient extends IntegrationHttpClient
{
    public array $calls = [];
    private int $index  = 0;

    public function __construct(string $baseUrl, private array $responses)
    {
        parent::__construct($baseUrl, [], 'TesteSimob');
    }

    public function get(string $endpoint, array $query = []): array
    {
        $this->calls[] = ['method' => 'get', 'endpoint' => $endpoint, 'query' => $query];

        return $this->next();
    }

    public function postMultipart(string $endpoint, array $fields): array
    {
        $this->calls[] = ['method' => 'postMultipart', 'endpoint' => $endpoint, 'fields' => $fields];

        return $this->next();
    }

    private function next(): array
    {
        return $this->responses[$this->index++] ?? [];
    }
}
