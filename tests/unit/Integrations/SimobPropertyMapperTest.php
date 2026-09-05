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

    // ------------------------------------------------------------- fixtures

    public function testMapeiaODetalheRealDaDocumentacao(): void
    {
        $detail = $this->fixture('detalhe_imovel')['result'][0];

        $p = $this->mapper()->mapDetail($detail);

        $this->assertNotNull($p);
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

        // "LOTE URBANO" sem de/para cadastrado cai no palpite por nome.
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

        $p = $this->mapper()->mapDetail($detail);

        $this->assertStringContainsString('LOTEAMENTO: Santa Marta', $p->fields['descricao']);
        $this->assertStringContainsString('PROXIMIDADE: Cerâmica Wunsch', $p->fields['descricao']);
    }

    public function testMontaAUrlDeImagemViaBaseUrlImagemComACapaNaPrimeira(): void
    {
        $detail = $this->fixture('detalhe_imovel')['result'][0];

        $p = $this->mapper()->mapDetail($detail);

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

        // Sem configVenda/configLocacao, o tipo vem da finalidade da listagem.
        $p = $this->mapper()->mapDetail($item, $item);

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

        $mapper = $this->mapper([], [
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
        $p = $this->mapper()->mapDetail([
            'id'            => '10',
            'configVenda'   => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '450000.00'],
            'configLocacao' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '2500.00'],
            'cidade'        => 'Chapecó',
            'bairro'        => 'Centro',
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
        $p = $this->mapper()->mapDetail([
            'id'            => '11',
            'configVenda'   => ['disponibilizarPortal' => true, 'inativo' => true, 'valor' => '450000.00'],
            'configLocacao' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '2500.00'],
            'cidade'        => 'Chapecó',
        ]);

        $this->assertSame('ALUGUEL', $p->fields['tipo_negocio']);
        $this->assertSame(2500.0, $p->fields['preco']);
    }

    public function testNaoLiberadoParaPortalNaoConta(): void
    {
        $p = $this->mapper()->mapDetail([
            'id'          => '12',
            'configVenda' => ['disponibilizarPortal' => false, 'inativo' => false, 'valor' => '450000.00'],
            'cidade'      => 'Chapecó',
        ]);

        $this->assertNull($p, 'sem nenhuma finalidade válida, o imóvel não entra');
    }

    public function testImovelComPrevisaoDeSaidaEntraPausado(): void
    {
        $p = $this->mapper([], [], ['initial_status' => 'ACTIVE'])->mapDetail([
            'id'            => '13',
            'configLocacao' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1200.00'],
            'dataPrevSaida' => '2026-09-30',
            'cidade'        => 'Chapecó',
        ]);

        $this->assertSame('PAUSED', $p->fields['status']);
    }

    public function testStatusInicialDoTenantERespeitado(): void
    {
        $p = $this->mapper([], [], ['initial_status' => 'ACTIVE'])->mapDetail([
            'id'          => '14',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1.00'],
            'cidade'      => 'Chapecó',
        ]);

        $this->assertSame('ACTIVE', $p->fields['status']);
    }

    // ----------------------------------------------------- de/para do tenant

    public function testDeParaDeCategoriaTemPrecedenciaSobreOPalpite(): void
    {
        $mapper = $this->mapper([
            '26' => $this->mapping(['external_id' => '26', 'target_value' => 'TERRENO']),
        ]);

        $detail = $this->fixture('detalhe_imovel')['result'][0];
        $p      = $mapper->mapDetail($detail);

        $this->assertSame('TERRENO', $p->fields['tipo_imovel'], 'palpite diria LOTE');
    }

    /**
     * Mapear para NULL é uma escolha do tenant ("essa característica não me
     * interessa"), e o palpite não pode desfazê-la.
     */
    public function testCaracteristicaMapeadaParaNadaNaoVoltaPeloPalpite(): void
    {
        $mapper = $this->mapper([], [
            '41' => $this->mapping(['external_id' => '41', 'target_field' => null]),
        ]);

        $p = $mapper->mapDetail([
            'id'          => '20',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
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
        $p = $this->mapper()->mapDetail([
            'id'          => '21',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
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
        $mapper = $this->mapper([], [
            '1' => $this->mapping(['external_id' => '1', 'target_field' => $campo]),
        ]);

        $p = $mapper->mapDetail([
            'id'          => '30',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
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
        $mapper = $this->mapper([], [
            '1' => $this->mapping(['external_id' => '1', 'target_field' => 'area_total']),
        ]);

        $p = $mapper->mapDetail([
            'id'          => '31',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
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

        $p = $this->mapper([], [], ['max_images' => 3])->mapDetail([
            'id'            => '40',
            'configVenda'   => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'        => 'Chapecó',
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
        $p = $this->mapper()->mapDetail([
            'id'            => '41',
            'configVenda'   => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'        => 'Chapecó',
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

        $a = $this->mapper()->mapDetail($detail);
        $b = $this->mapper()->mapDetail($detail);

        $this->assertSame($a->images[0]->url, $b->images[0]->url);
        $this->assertSame($a->contentHash(), $b->contentHash());
    }

    // ------------------------------------------------------------ endereço

    public function testUfInvalidaEDescartadaEmVezDeQuebrarAValidacao(): void
    {
        $p = $this->mapper()->mapDetail([
            'id'          => '50',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
            'uf'          => 'Santa Catarina',
        ]);

        $this->assertArrayNotHasKey('estado', $p->fields, 'validatePropertyData exige exatamente 2 caracteres');
    }

    public function testLatitudeELongitudeDoEnderecoDetalhado(): void
    {
        $p = $this->mapper()->mapDetail([
            'id'          => '51',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
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
        $p = $this->mapper()->mapDetail([
            'id'          => '60',
            'configVenda' => ['disponibilizarPortal' => true, 'inativo' => false, 'valor' => '1'],
            'cidade'      => 'Chapecó',
            'bairro'      => 'Centro',
            'categoria'   => ['id' => 1, 'descricao' => 'APARTAMENTO'],
        ]);

        $this->assertSame('Apartamento - Centro - Chapecó', $p->fields['titulo']);
    }
}
