<?php

namespace Tests\Unit\Integrations;

use App\Entities\IntegrationMapping;
use App\Libraries\Integrations\Simob\SimobPropertyMapper;
use PHPUnit\Framework\TestCase;

/**
 * O mapper roda contra as fixtures reais extraídas da coleção Postman do Simob
 * (tests/_support/fixtures/simob), não contra payloads inventados. É o que
 * garante que as diferenças entre o formato de listagem e o de detalhe estão
 * cobertas de verdade.
 *
 * @internal
 */
final class SimobPropertyMapperTest extends TestCase
{
    private const BASE = 'https://demo.simob.com.br';

    private function fixture(string $name): array
    {
        $path = dirname(__DIR__, 2) . '/_support/fixtures/simob/' . $name . '.json';

        return json_decode(file_get_contents($path), true);
    }

    private function mapping(array $attrs): IntegrationMapping
    {
        return new IntegrationMapping($attrs);
    }

    private function mapper(array $categories = [], array $characteristics = [], array $settings = []): SimobPropertyMapper
    {
        return new SimobPropertyMapper(self::BASE, $categories, $characteristics, $settings);
    }

    /** Categoria genérica (id 1 -> CASA), pronta pra usar no payload sintético. */
    private const CATEGORIA_GENERICA = ['id' => 1, 'descricao' => 'CASA'];

    /**
     * mapper() com a categoria genérica já confirmada — pra testes que não
     * têm nada a ver com resolução de categoria. resolveTipoImovel() agora
     * exige de/para confirmado (ver testCategoriaSemDeParaNaoViraCasa);
     * sem isso, todo payload sintético sem 'categoria'/'idCategoria' viraria
     * "ignorado" e nenhuma das outras asserções do teste faria sentido.
     */
    private function mapperGenerico(array $characteristics = [], array $settings = []): SimobPropertyMapper
    {
        return $this->mapper(
            ['1' => $this->mapping(['external_id' => '1', 'target_value' => 'CASA'])],
            $characteristics,
            $settings
        );
    }

    // ------------------------------------------------------------- fixtures

    public function testMapeiaODetalheRealDaDocumentacao(): void
    {
        $detail = $this->fixture('detalhe_imovel')['result'][0];

        // A categoria da fixture (26 = LOTE URBANO) precisa de um de/para
        // confirmado: sem mapping em tempo de sync, categoria não decide
        // sozinha o tipo_imovel (ver testCategoriaSemDeParaNaoViraCasa).
        $mapper = $this->mapper([
            '26' => $this->mapping(['external_id' => '26', 'target_value' => 'LOTE']),
        ]);

        $p = $mapper->mapDetail($detail);

        $this->assertNotNull($p);
        $this->assertNull($p->ignoreReason);
        $this->assertSame('3376', $p->externalId);
        $this->assertSame('3364', $p->externalCode);

        // configVenda ativa e configLocacao nula -> só venda.
        $this->assertSame('VENDA', $p->fields['tipo_negocio']);
        $this->assertSame(105000.0, $p->fields['preco']);

        $this->assertSame('SÃO MIGUEL DO OESTE', $p->fields['cidade']);
        $this->assertSame('PROGRESSO', $p->fields['bairro']);
        $this->assertSame('SC', $p->fields['estado']);
        $this->assertSame('RUA A', $p->fields['rua']);
        $this->assertSame('89900000', $p->fields['cep']);

        $this->assertSame('LOTE', $p->fields['tipo_imovel']);

        // O código na frente do nome não interessa a quem navega no portal.
        $this->assertSame('RUA A - N° 04 - PROGRESSO', $p->fields['titulo']);
    }

    /**
     * Sem de/para, característica nenhuma pode ser descartada: enquanto o
     * tenant não termina o mapeamento, é esse texto que vende o imóvel.
     */
    public function testCaracteristicaSemDeParaVaiParaADescricao(): void
    {
        $detail = $this->fixture('detalhe_imovel')['result'][0];

        $mapper = $this->mapper([
            '26' => $this->mapping(['external_id' => '26', 'target_value' => 'LOTE']),
        ]);

        $p = $mapper->mapDetail($detail);

        $this->assertStringContainsString('LOTEAMENTO: Santa Marta', $p->fields['descricao']);
        $this->assertStringContainsString('PROXIMIDADE: Cerâmica Wunsch', $p->fields['descricao']);
    }

    /**
     * "ÁREA DA EDIFICAÇÃO EM M²" é o rótulo real usado pela imobiliária
     * Giusti — nenhum fragmento de SimobVocabulary::CHARACTERISTIC_GUESSES
     * batia com ele, então toda área construída ficava só no texto da
     * descrição em vez do campo estruturado.
     */
    public function testAreaDaEdificacaoVaiParaAreaConstruida(): void
    {
        $p = $this->mapperGenerico()->mapDetail([
            'id'          => '42',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'São Miguel do Oeste',
            'categoria'   => self::CATEGORIA_GENERICA,
            'caracteristicas' => [
                ['id' => 28885, 'descricao' => 'ÁREA DA EDIFICAÇÃO EM M²', 'valor' => '174', 'tipo' => 4],
            ],
        ]);

        $this->assertSame(174.0, $p->fields['area_construida']);
    }

    public function testMontaAUrlDeImagemViaBaseUrlImagemComACapaNaPrimeira(): void
    {
        $detail = $this->fixture('detalhe_imovel')['result'][0];

        $mapper = $this->mapper([
            '26' => $this->mapping(['external_id' => '26', 'target_value' => 'LOTE']),
        ]);

        $p = $mapper->mapDetail($detail);

        $this->assertCount(1, $p->images);
        $this->assertSame(
            self::BASE . '/arquivos_imobiliaria/imobiliaria_1/imovel_3376/9f2bcfc4be1ee43c1b10304b1ac280e6883eb9c8.jpg',
            $p->images[0]->url
        );
        $this->assertTrue($p->images[0]->principal);
    }

    public function testMapeiaOFormatoDeListagemQueDifereDoDetalhe(): void
    {
        $item = $this->fixture('filtro_imoveis')['result'][0];

        $mapper = $this->mapper([
            '17' => $this->mapping(['external_id' => '17', 'target_value' => 'APARTAMENTO']),
        ]);

        // Sem configVenda/configLocacao, o tipo vem da finalidade da listagem.
        $p = $mapper->mapDetail($item, $item);

        $this->assertNotNull($p);
        $this->assertSame('ALUGUEL', $p->fields['tipo_negocio'], 'finalidade 1 = locação');
        $this->assertSame(950.0, $p->fields['preco']);
        $this->assertSame('APARTAMENTO', $p->fields['tipo_imovel']);
        $this->assertSame('CENTRO', $p->fields['bairro']);
        $this->assertSame('2020-08-01 11:25:20', $p->externalUpdatedAt);
    }

    /**
     * Na listagem a característica usa `tipoCaracteristica`; no detalhe, `tipo`.
     * O mapper precisa entender as duas.
     */
    public function testLeCaracteristicaNoFormatoDeListagem(): void
    {
        $item = $this->fixture('destaques')['result'][0];

        $mapper = $this->mapper([
            '17' => $this->mapping(['external_id' => '17', 'target_value' => 'APARTAMENTO']),
        ], [
            '41' => $this->mapping(['external_id' => '41', 'target_field' => 'quartos']),
        ]);

        $p = $mapper->mapDetail($item, $item);

        $this->assertSame(2, $p->fields['quartos'], 'DORMITÓRIO(S) = 2 na fixture');
    }

    public function testSummarizeExtraiOMinimoParaOCorteIncremental(): void
    {
        $item = $this->fixture('filtro_imoveis')['result'][0];

        $resumo = $this->mapper()->summarize($item);

        $this->assertSame('1136', $resumo['external_id']);
        $this->assertSame('1143', $resumo['external_code']);
        $this->assertSame('2020-08-01 11:25:20', $resumo['updated_at']);
    }

    // -------------------------------------------------------- tipo_negocio

    public function testImovelNasDuasConfiguracoesViraVendaAluguel(): void
    {
        $p = $this->mapperGenerico()->mapDetail([
            'id'            => '10',
            'configVenda'   => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '450000.00'],
            'configLocacao' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '2500.00'],
            'cidade'        => 'Chapecó',
            'bairro'        => 'Centro',
            'categoria'     => self::CATEGORIA_GENERICA,
        ]);

        $this->assertSame('VENDA_ALUGUEL', $p->fields['tipo_negocio']);
        // Entre os dois preços vale o de venda: é o que o portal mostra e o que
        // faz o filtro de faixa de preço fazer sentido.
        $this->assertSame(450000.0, $p->fields['preco']);
    }

    /**
     * Imóvel inativo ou não liberado para portal no Simob não pode vazar para
     * o Habitaweb — a decisão é da imobiliária e tem que ser respeitada.
     */
    public function testConfiguracaoInativaNaoConta(): void
    {
        $p = $this->mapperGenerico()->mapDetail([
            'id'            => '11',
            'configVenda'   => ['disponibilizarPortal' => true, 'inativo' => true, 'valor' => '450000.00'],
            'configLocacao' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '2500.00'],
            'cidade'        => 'Chapecó',
            'categoria'     => self::CATEGORIA_GENERICA,
        ]);

        $this->assertSame('ALUGUEL', $p->fields['tipo_negocio']);
        $this->assertSame(2500.0, $p->fields['preco']);
    }

    public function testNaoLiberadoParaPortalNaoConta(): void
    {
        $p = $this->mapperGenerico()->mapDetail([
            'id'          => '12',
            'configVenda' => ['disponibilizarPortal' => false, 'inativo' => false, 'valor' => '450000.00'],
            'cidade'      => 'Chapecó',
            'categoria'   => self::CATEGORIA_GENERICA,
        ]);

        $this->assertNull($p, 'sem nenhuma finalidade válida, o imóvel não entra');
    }

    public function testImovelComPrevisaoDeSaidaEntraPausado(): void
    {
        $p = $this->mapperGenerico([], ['initial_status' => 'ACTIVE'])->mapDetail([
            'id'            => '13',
            'configLocacao' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1200.00'],
            'dataPrevSaida' => '2026-09-30',
            'cidade'        => 'Chapecó',
            'categoria'     => self::CATEGORIA_GENERICA,
        ]);

        $this->assertSame('PAUSED', $p->fields['status']);
    }

    public function testStatusInicialDoTenantERespeitado(): void
    {
        $p = $this->mapperGenerico([], ['initial_status' => 'ACTIVE'])->mapDetail([
            'id'          => '14',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1.00'],
            'cidade'      => 'Chapecó',
            'categoria'   => self::CATEGORIA_GENERICA,
        ]);

        $this->assertSame('ACTIVE', $p->fields['status']);
    }

    // ----------------------------------------------------- de/para do tenant

    public function testDeParaDeCategoriaResolveOTipoDoImovel(): void
    {
        $mapper = $this->mapper([
            '26' => $this->mapping(['external_id' => '26', 'target_value' => 'TERRENO']),
        ]);

        $detail = $this->fixture('detalhe_imovel')['result'][0];
        $p      = $mapper->mapDetail($detail);

        $this->assertSame('TERRENO', $p->fields['tipo_imovel']);
    }

    /**
     * Categoria sem NENHUMA linha em integration_mappings (nunca vista,
     * nunca descoberta): o item é ignorado, não adivinhado por nome. O
     * palpite (SimobVocabulary::guessPropertyType) só existe como SUGESTÃO
     * dentro de IntegrationService::seedMappings(), pra o tenant confirmar
     * na tela — decidir o tipo do imóvel sozinho, em tempo de sync, é
     * exatamente o que fazia "SEDE ESPORTIVA" virar CASA em silêncio.
     */
    public function testCategoriaSemDeParaNaoViraCasa(): void
    {
        $p = $this->mapper()->mapDetail([
            'id'          => '70',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
            'categoria'   => ['id' => 999, 'descricao' => 'SEDE ESPORTIVA'],
        ]);

        $this->assertNotNull($p);
        $this->assertSame([], $p->fields);
        $this->assertSame('categoria não mapeada: SEDE ESPORTIVA', $p->ignoreReason);
    }

    /**
     * "— Não importar —" na tela de mapeamentos grava a linha com
     * target_value vazio — é a escolha explícita do tenant, e tem que valer
     * tanto quanto uma categoria nunca vista.
     */
    public function testCategoriaMarcadaComoNaoImportarEIgnorada(): void
    {
        $mapper = $this->mapper([
            '26' => $this->mapping(['external_id' => '26', 'target_value' => null]),
        ]);

        $p = $mapper->mapDetail($this->fixture('detalhe_imovel')['result'][0]);

        $this->assertNotNull($p);
        $this->assertNotNull($p->ignoreReason);
    }

    /**
     * configVenda/configLocacao PRESENTES mas recusados (inativo, ou fora do
     * portal) não podem cair pro valor/finalidade da listagem — isso
     * ressuscitaria uma finalidade que a própria imobiliária já desativou no
     * Simob. Só a AUSÊNCIA total das duas configs cai pro palpite da listagem
     * (a doc do Simob avisa que "Dados Imóvel" nem sempre está online).
     */
    public function testPortalDesabilitadoNaoCaiNoValorDaListagem(): void
    {
        $p = $this->mapperGenerico()->mapDetail(
            [
                'id'          => '71',
                'configVenda' => ['disponibilizarPortal' => false, 'inativo' => false, 'valor' => '1'],
                'cidade'      => 'Chapecó',
                'categoria'   => self::CATEGORIA_GENERICA,
            ],
            // Listagem diz que era pra estar à venda por R$ 999999 — não pode vazar.
            ['finalidade' => 2, 'valor' => '999999.00']
        );

        $this->assertNull($p, 'config presente mas recusada não cai pro palpite da listagem');
    }

    /**
     * Mapear para NULL é uma escolha do tenant ("essa característica não me
     * interessa"), e o palpite não pode desfazê-la.
     */
    public function testCaracteristicaMapeadaParaNadaNaoVoltaPeloPalpite(): void
    {
        $mapper = $this->mapper([
            '1' => $this->mapping(['external_id' => '1', 'target_value' => 'CASA']),
        ], [
            '41' => $this->mapping(['external_id' => '41', 'target_field' => null]),
        ]);

        $p = $mapper->mapDetail([
            'id'          => '20',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
            'categoria'   => self::CATEGORIA_GENERICA,
            'caracteristicas' => [
                ['id' => 41, 'descricao' => 'DORMITÓRIO(S)', 'valor' => '3', 'tipo' => 3],
            ],
        ]);

        $this->assertArrayNotHasKey('quartos', $p->fields);
        $this->assertStringContainsString('DORMITÓRIO(S): 3', $p->fields['descricao']);
    }

    /**
     * Característica criada depois da última descoberta ainda não tem linha em
     * integration_mappings. Perder o dado até o tenant redescobrir seria pior
     * que usar o palpite.
     */
    public function testCaracteristicaDesconhecidaUsaOPalpite(): void
    {
        $p = $this->mapperGenerico()->mapDetail([
            'id'          => '21',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
            'categoria'   => self::CATEGORIA_GENERICA,
            'caracteristicas' => [
                ['id' => 999, 'descricao' => 'Vaga de garagem', 'valor' => '2', 'tipo' => 3],
            ],
        ]);

        $this->assertSame(2, $p->fields['vagas']);
    }

    // ------------------------------------------------- tipos de característica

    /**
     * @dataProvider valoresDeCaracteristica
     */
    public function testConverteOValorConformeAColunaDestino(string $campo, mixed $valor, int $tipo, mixed $esperado): void
    {
        $mapper = $this->mapper([
            '9' => $this->mapping(['external_id' => '9', 'target_value' => 'CASA']),
        ], [
            '1' => $this->mapping(['external_id' => '1', 'target_field' => $campo]),
        ]);

        $p = $mapper->mapDetail([
            'id'          => '30',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
            'categoria'   => ['id' => 9, 'descricao' => 'CASA'],
            'caracteristicas' => [['id' => 1, 'descricao' => 'X', 'valor' => $valor, 'tipo' => $tipo]],
        ]);

        $this->assertSame($esperado, $p->fields[$campo] ?? null);
    }

    public static function valoresDeCaracteristica(): array
    {
        return [
            'inteiro puro'                => ['quartos', '3', 3, 3],
            'inteiro vindo como texto'    => ['quartos', '3 quartos', 2, 3],
            'decimal brasileiro com m2'   => ['area_total', '286,65 m²', 4, 286.65],
            'decimal americano'           => ['area_construida', '120.50', 4, 120.5],
            'moeda brasileira'            => ['valor_condominio', 'R$ 1.260,00', 5, 1260.0],
            'sim vira true'               => ['mobiliado', 'Sim', 1, true],
            'nao vira false'              => ['aceita_pets', 'Não', 1, false],
            'um vira true'                => ['mobiliado', '1', 1, true],
            'zero vira false'             => ['mobiliado', '0', 1, false],
            'texto livre no tipo sim/nao' => ['mobiliado', 'Sim, com armários', 1, true],
            'vazio nao preenche'          => ['quartos', '', 3, null],
            'nao numerico nao preenche'   => ['quartos', 'muitos', 3, null],
        ];
    }

    /**
     * Área negativa é dado sujo na origem; gravar isso quebraria o filtro de
     * busca do portal.
     */
    public function testValorNegativoEmAreaEDescartado(): void
    {
        $mapper = $this->mapper([
            '9' => $this->mapping(['external_id' => '9', 'target_value' => 'CASA']),
        ], [
            '1' => $this->mapping(['external_id' => '1', 'target_field' => 'area_total']),
        ]);

        $p = $mapper->mapDetail([
            'id'          => '31',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
            'categoria'   => ['id' => 9, 'descricao' => 'CASA'],
            'caracteristicas' => [['id' => 1, 'descricao' => 'Área', 'valor' => '-50', 'tipo' => 4]],
        ]);

        $this->assertArrayNotHasKey('area_total', $p->fields);
    }

    // ------------------------------------------------------------- imagens

    public function testImagensRespeitamAOrdemEOTetoDoTenant(): void
    {
        $imagens = [];

        for ($i = 5; $i >= 1; $i--) {
            $imagens[] = ['baseNomeImagem' => "img{$i}", 'extensao' => 'jpg', 'posicao' => $i];
        }

        $p = $this->mapperGenerico([], ['max_images' => 3])->mapDetail([
            'id'            => '40',
            'configVenda'   => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'        => 'Chapecó',
            'categoria'     => self::CATEGORIA_GENERICA,
            'baseUrlImagem' => 'arquivos_imobiliaria/imobiliaria_1/imovel_40',
            'imagens'       => $imagens,
        ]);

        $this->assertCount(3, $p->images);
        $this->assertStringContainsString('img1.jpg', $p->images[0]->url, 'posição 1 primeiro');
        $this->assertTrue($p->images[0]->principal);
        $this->assertFalse($p->images[1]->principal, 'só pode haver uma capa');
    }

    public function testImagemSemNomeOuExtensaoEIgnorada(): void
    {
        $p = $this->mapperGenerico()->mapDetail([
            'id'            => '41',
            'configVenda'   => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'        => 'Chapecó',
            'categoria'     => self::CATEGORIA_GENERICA,
            'baseUrlImagem' => 'arquivos_imobiliaria/imobiliaria_1/imovel_41',
            'imagens'       => [
                ['baseNomeImagem' => '', 'extensao' => 'jpg'],
                ['baseNomeImagem' => 'ok', 'extensao' => ''],
                ['baseNomeImagem' => 'bom', 'extensao' => 'png', 'posicao' => 1],
            ],
        ]);

        $this->assertCount(1, $p->images);
        $this->assertStringContainsString('bom.png', $p->images[0]->url);
    }

    /**
     * addMediaFromUrl deduplica por sha256(url). Se a URL mudasse entre
     * rodadas, todo sync rebaixaria o catálogo de fotos inteiro.
     */
    public function testUrlDaImagemEEstavelEntreChamadas(): void
    {
        $detail = $this->fixture('detalhe_imovel')['result'][0];

        $categoria = ['26' => $this->mapping(['external_id' => '26', 'target_value' => 'LOTE'])];
        $a = $this->mapper($categoria)->mapDetail($detail);
        $b = $this->mapper($categoria)->mapDetail($detail);

        $this->assertSame($a->images[0]->url, $b->images[0]->url);
        $this->assertSame($a->contentHash(), $b->contentHash());
    }

    // ------------------------------------------------------------ endereço

    public function testUfInvalidaEDescartadaEmVezDeQuebrarAValidacao(): void
    {
        $p = $this->mapperGenerico()->mapDetail([
            'id'          => '50',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
            'categoria'   => self::CATEGORIA_GENERICA,
            'uf'          => 'Santa Catarina',
        ]);

        $this->assertArrayNotHasKey('estado', $p->fields, 'validatePropertyData exige exatamente 2 caracteres');
    }

    public function testLatitudeELongitudeDoEnderecoDetalhado(): void
    {
        $p = $this->mapperGenerico()->mapDetail([
            'id'          => '51',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
            'categoria'   => self::CATEGORIA_GENERICA,
            'endereco'    => ['localizacao' => ['latitude' => '-27.1022939', 'longitude' => '-52.6129464']],
        ]);

        $this->assertSame(-27.1022939, $p->fields['latitude']);
        $this->assertSame(-52.6129464, $p->fields['longitude']);
    }

    public function testItemSemIdEDescartado(): void
    {
        $this->assertNull($this->mapper()->mapDetail(['codigo' => '123']));
        $this->assertNull($this->mapper()->summarize(['codigo' => '123']));
    }

    public function testTituloCaiParaTipoBairroCidadeQuandoNaoHaNome(): void
    {
        $mapper = $this->mapper([
            '1' => $this->mapping(['external_id' => '1', 'target_value' => 'APARTAMENTO']),
        ]);

        $p = $mapper->mapDetail([
            'id'          => '60',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
            'bairro'      => 'Centro',
            'categoria'   => ['id' => 1, 'descricao' => 'APARTAMENTO'],
        ]);

        $this->assertSame('Apartamento - Centro - Chapecó', $p->fields['titulo']);
    }
}
