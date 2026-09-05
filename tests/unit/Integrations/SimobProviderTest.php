<?php

namespace Tests\Unit\Integrations;

use App\Libraries\Integrations\Dto\CatalogItem;
use App\Libraries\Integrations\Dto\SyncCursor;
use App\Libraries\Integrations\Exceptions\IntegrationException;
use App\Libraries\Integrations\IntegrationProviderInterface;
use App\Libraries\Integrations\Simob\SimobClient;
use App\Libraries\Integrations\Simob\SimobProvider;
use PHPUnit\Framework\TestCase;

/**
 * Client e provider do Simob, sem rede: o SimobClient é substituído por uma
 * dublê que registra as chamadas e devolve respostas roteirizadas.
 *
 * @internal
 */
final class SimobProviderTest extends TestCase
{
    private function fixture(string $name): array
    {
        return json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/_support/fixtures/simob/' . $name . '.json'),
            true
        );
    }

    private function provider(FakeSimobClient $client): SimobProvider
    {
        $p = new SimobProvider();
        $p->configure(['base_url' => 'https://demo.simob.com.br', 'token' => 'tok']);
        $p->setClient($client);

        return $p;
    }

    // -------------------------------------------------------------- conexão

    public function testTesteDeConexaoOkInformaQuantasCategorias(): void
    {
        $client = new FakeSimobClient(['categories' => $this->fixture('categorias')['result']]);

        $result = $this->provider($client)->validateConfig();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('2 categoria', $result->message);
    }

    /**
     * 200 com lista vazia significa token válido de OUTRA imobiliária, ou
     * nenhum imóvel liberado para o site. Reportar sucesso aqui faria o tenant
     * ligar o sync e não entender por que o catálogo nunca aparece.
     */
    public function testCategoriaVaziaNaoEConsideradaSucesso(): void
    {
        $client = new FakeSimobClient(['categories' => []]);

        $result = $this->provider($client)->validateConfig();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('nenhuma categoria', $result->message);
    }

    public function testFalhaDeConexaoViraMensagemLegivel(): void
    {
        $client = new FakeSimobClient(['categoriesThrow' => new IntegrationException('Credencial recusada pela plataforma externa.')]);

        $result = $this->provider($client)->validateConfig();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Credencial recusada', $result->message);
    }

    public function testDeclaraAsDuasCapacidades(): void
    {
        $p = $this->provider(new FakeSimobClient([]));

        $this->assertTrue($p->supports(IntegrationProviderInterface::CAP_IMPORT_PROPERTIES));
        $this->assertTrue($p->supports(IntegrationProviderInterface::CAP_PUSH_LEADS));
    }

    // ---------------------------------------------------------- mapeamentos

    public function testDescobreCategoriasECaracteristicasDasDuasFinalidades(): void
    {
        $client = new FakeSimobClient([
            'categories'      => $this->fixture('categorias')['result'],
            'characteristics' => [
                SimobClient::FINALIDADE_LOCACAO => [['id' => 249, 'descricao' => 'Dormitório(s)', 'idTipoCaracteristica' => 3]],
                SimobClient::FINALIDADE_VENDA   => [
                    ['id' => 249, 'descricao' => 'Dormitório(s)', 'idTipoCaracteristica' => 3],
                    ['id' => 264, 'descricao' => 'Vaga de garagem', 'idTipoCaracteristica' => 3],
                ],
            ],
        ]);

        $found = $this->provider($client)->discoverMappings();

        $this->assertCount(2, $found['category']);
        $this->assertSame('Apartamento', $found['category'][0]['external_label']);

        // A união das duas finalidades, sem repetir o que aparece nas duas.
        $this->assertCount(2, $found['characteristic']);
        $this->assertSame('3', $found['characteristic'][0]['external_type']);
    }

    /**
     * Se a lista de características de uma finalidade falhar, a descoberta não
     * pode ir junto: melhor um de/para parcial que nenhum.
     */
    public function testDescobertaSobreviveAFalhaDeUmaFinalidade(): void
    {
        $client = new FakeSimobClient([
            'categories'           => $this->fixture('categorias')['result'],
            'characteristics'      => [SimobClient::FINALIDADE_VENDA => [['id' => 1, 'descricao' => 'Suíte']]],
            'characteristicsThrow' => [SimobClient::FINALIDADE_LOCACAO => new IntegrationException('fora do ar')],
        ]);

        $found = $this->provider($client)->discoverMappings();

        $this->assertCount(1, $found['characteristic']);
    }

    // -------------------------------------------------------------- catálogo

    public function testPercorreOCatalogoDasDuasFinalidadesSemRepetirImovel(): void
    {
        // O mesmo id 100 aparece nas duas finalidades (está à venda e para
        // alugar): tem que ser processado uma vez só.
        $client = new FakeSimobClient([
            'pages' => [
                SimobClient::FINALIDADE_LOCACAO => [[
                    ['id' => '100', 'codigo' => 'A100', 'updatedAt' => '2026-08-10 10:00:00'],
                    ['id' => '101', 'codigo' => 'A101', 'updatedAt' => '2026-08-09 10:00:00'],
                ]],
                SimobClient::FINALIDADE_VENDA => [[
                    ['id' => '100', 'codigo' => 'A100', 'updatedAt' => '2026-08-10 10:00:00'],
                    ['id' => '102', 'codigo' => 'A102', 'updatedAt' => '2026-08-08 10:00:00'],
                ]],
            ],
        ]);

        $itens = iterator_to_array($this->provider($client)->fetchCatalog(new SyncCursor(null)));

        $this->assertCount(3, $itens);
        $this->assertSame(['100', '101', '102'], array_map(static fn (CatalogItem $i) => $i->externalId, $itens));
    }

    /**
     * O corte incremental é a única defesa contra varrer o catálogo inteiro em
     * toda rodada — a API do Simob não tem filtro updated_since.
     */
    public function testParaDePaginarAoChegarEmConteudoAnteriorAoUltimoSync(): void
    {
        $paginaCheia = [];

        for ($i = 0; $i < SimobClient::PAGE_SIZE; $i++) {
            $paginaCheia[] = [
                'id'        => (string) (1000 + $i),
                'codigo'    => 'C' . (1000 + $i),
                // A última da página é anterior ao corte.
                'updatedAt' => $i < 40 ? '2026-08-10 10:00:00' : '2026-07-01 10:00:00',
            ];
        }

        $client = new FakeSimobClient([
            'pages' => [
                SimobClient::FINALIDADE_LOCACAO => [$paginaCheia, [['id' => '9999', 'codigo' => 'C9999', 'updatedAt' => '2026-06-01 10:00:00']]],
            ],
        ]);

        $cursor = new SyncCursor('2026-08-01 00:00:00');
        $itens  = iterator_to_array($this->provider($client)->fetchCatalog($cursor, ['finalidades' => [SimobClient::FINALIDADE_LOCACAO]]));

        $this->assertCount(SimobClient::PAGE_SIZE, $itens, 'devolve a página que já buscou');
        $this->assertSame(1, $client->listCalls, 'mas não pede a página seguinte');
    }

    public function testPaginaIncompletaEncerraAFinalidade(): void
    {
        $client = new FakeSimobClient([
            'pages' => [
                SimobClient::FINALIDADE_LOCACAO => [
                    [['id' => '1', 'codigo' => 'C1', 'updatedAt' => '2026-08-10 10:00:00']],
                    [['id' => '2', 'codigo' => 'C2', 'updatedAt' => '2026-08-09 10:00:00']],
                ],
            ],
        ]);

        $itens = iterator_to_array($this->provider($client)->fetchCatalog(
            new SyncCursor(null),
            ['finalidades' => [SimobClient::FINALIDADE_LOCACAO]]
        ));

        $this->assertCount(1, $itens);
        $this->assertSame(1, $client->listCalls);
    }

    /**
     * O detalhe é I/O caro: uma requisição por imóvel. Ele não pode acontecer
     * enquanto o sync está só decidindo o que mudou.
     */
    public function testODetalheSoEBuscadoQuandoOItemEResolvido(): void
    {
        $client = new FakeSimobClient([
            'pages'  => [SimobClient::FINALIDADE_LOCACAO => [[['id' => '100', 'codigo' => 'A100', 'updatedAt' => '2026-08-10 10:00:00']]]],
            'detail' => ['A100' => [
                'id'          => '100',
                'codigo'      => 'A100',
                'nome'        => 'Casa boa',
                'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '300000.00'],
                'cidade'      => 'Chapecó',
                'bairro'      => 'Centro',
            ]],
        ]);

        $itens = iterator_to_array($this->provider($client)->fetchCatalog(
            new SyncCursor(null),
            ['finalidades' => [SimobClient::FINALIDADE_LOCACAO]]
        ));

        $this->assertSame(0, $client->detailCalls, 'iterar não pode buscar detalhe');

        $property = $itens[0]->resolve();

        $this->assertSame(1, $client->detailCalls);
        $this->assertSame('Casa boa', $property->fields['titulo']);
        $this->assertSame('VENDA', $property->fields['tipo_negocio']);
    }

    /**
     * A doc do Simob avisa que certas rotas nem sempre estão online. Perder o
     * imóvel por causa disso é pior que aproveitar o que a listagem trouxe.
     */
    public function testDetalheIndisponivelCaiParaOsDadosDaListagem(): void
    {
        $client = new FakeSimobClient([
            'pages'  => [SimobClient::FINALIDADE_LOCACAO => [[[
                'id'         => '100',
                'codigo'     => 'A100',
                'updatedAt'  => '2026-08-10 10:00:00',
                'finalidade' => 1,
                'valor'      => '1500.00',
                'cidade'     => 'Chapecó',
                'bairro'     => 'Centro',
                'descricaoCategoria' => 'APARTAMENTO',
            ]]]],
            'detail' => [],
        ]);

        $itens    = iterator_to_array($this->provider($client)->fetchCatalog(new SyncCursor(null), ['finalidades' => [1]]));
        $property = $itens[0]->resolve();

        $this->assertNotNull($property);
        $this->assertSame('ALUGUEL', $property->fields['tipo_negocio']);
        $this->assertSame(1500.0, $property->fields['preco']);
    }

    // ------------------------------------------------------------------ lead

    public function testEnviaLeadComoInteresseEDevolveSucesso(): void
    {
        $client = new FakeSimobClient(['interest' => ['success' => true, 'result' => ['id' => 55]]]);

        $result = $this->provider($client)->pushLead([
            'lead' => [
                'nome'     => 'Maria Souza',
                'email'    => 'maria@exemplo.com',
                'telefone' => '(49) 99999-1234',
                'mensagem' => 'Tenho interesse em visitar.',
            ],
            'property' => [
                'external_id'           => '3376',
                'external_code'         => '3364',
                'external_categoria_id' => '17',
                'tipo_negocio'          => 'ALUGUEL',
                'cidade'                => 'Chapecó',
                'bairro'                => 'Centro',
                'estado'                => 'SC',
            ],
        ]);

        $this->assertTrue($result->success);

        $enviado = $client->lastInterest[0];
        $this->assertSame('Maria Souza', $enviado['nome']);
        $this->assertSame(SimobClient::FINALIDADE_LOCACAO, $enviado['finalidade']);
        $this->assertSame([17], $enviado['categoria'], 'categoria é obrigatória no Simob');
        $this->assertSame([3376], $enviado['idsImovel']);
        $this->assertSame('Habitaweb', $enviado['origem']);
        $this->assertStringContainsString('Imóvel 3364', $enviado['observacao']);
        $this->assertSame('sc', $enviado['enderecos'][0]['uf']);
        $this->assertSame('Centro', $enviado['enderecos'][0]['bairro'][0]['nome']);
    }

    public function testSimobRecusandoOInteresseViraFalha(): void
    {
        $client = new FakeSimobClient(['interest' => ['success' => false, 'message' => 'categoria inválida']]);

        $result = $this->provider($client)->pushLead([
            'lead'     => ['nome' => 'João'],
            'property' => ['external_id' => '1'],
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('categoria inválida', $result->message);
    }

    // ------------------------------------------------------------ URL de mídia

    public function testUrlDeImagemUsaOBaseUrlImagemEEscapaOsSegmentos(): void
    {
        $this->assertSame(
            'https://demo.simob.com.br/arquivos_imobiliaria/imobiliaria_1/imovel_3376/abc123.jpg',
            SimobClient::imageUrl(
                'https://demo.simob.com.br/',
                '/arquivos_imobiliaria/imobiliaria_1/imovel_3376/',
                'abc123',
                '.jpg'
            )
        );
    }
}

/**
 * Dublê do SimobClient. Herda para caber na assinatura do provider, mas não
 * chama o construtor pai — nada de HTTP aqui.
 */
final class FakeSimobClient extends SimobClient
{
    public int $listCalls   = 0;
    public int $detailCalls = 0;
    public array $lastInterest = [];

    /** @var array<int, list<list<array>>> finalidade => páginas */
    private array $pages;

    public function __construct(private array $script)
    {
        // Sem parent::__construct(): o client real exigiria um IntegrationHttpClient.
        $this->pages = $script['pages'] ?? [];
    }

    public function listCategories(int $finalidade = 3): array
    {
        if (isset($this->script['categoriesThrow'])) {
            throw $this->script['categoriesThrow'];
        }

        return $this->script['categories'] ?? [];
    }

    public function listCharacteristics(int $finalidade = 1): array
    {
        if (isset($this->script['characteristicsThrow'][$finalidade])) {
            throw $this->script['characteristicsThrow'][$finalidade];
        }

        return $this->script['characteristics'][$finalidade] ?? [];
    }

    public function listProperties(int $finalidade, int $firstResult, int $maxResults = SimobClient::PAGE_SIZE, int $trazerCaracteristicas = 50): array
    {
        $this->listCalls++;
        $index = (int) ($firstResult / SimobClient::PAGE_SIZE);

        return $this->pages[$finalidade][$index] ?? [];
    }

    public function countProperties(int $finalidade): int
    {
        return array_sum(array_map('count', $this->pages[$finalidade] ?? []));
    }

    public function getPropertyDetail(string $codigo): ?array
    {
        $this->detailCalls++;

        return $this->script['detail'][$codigo] ?? null;
    }

    public function createInterest(array $interesses): array
    {
        $this->lastInterest = $interesses;

        return $this->script['interest'] ?? ['success' => true];
    }
}
