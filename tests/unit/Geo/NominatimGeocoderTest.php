<?php

namespace Tests\Unit\Geo;

use App\Libraries\Geo\NominatimGeocoder;
use PHPUnit\Framework\TestCase;

/**
 * Sem tocar em rede: consultar() é trocado por respostas roteirizadas (mesmo
 * padrão de IntegrationHttpClientTest com IntegrationHttpClient::dispatch()).
 *
 * @internal
 */
final class NominatimGeocoderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Cada teste tem sua própria chave de consulta (endereços distintos),
        // mas limpar evita qualquer resíduo entre execuções da suíte inteira.
        cache()->clean();
    }

    public function testEnderecoCompletoAchaNaPrimeiraConsulta(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([
            'city=Chapecó&state=SC&street=Rua Teste 100' => ['lat' => -27.1, 'lng' => -52.6],
        ]);

        $resultado = $geocoder->geocode([
            'rua'    => 'Rua Teste',
            'numero' => '100',
            'bairro' => 'Centro',
            'cidade' => 'Chapecó',
            'estado' => 'SC',
        ]);

        $this->assertSame(['lat' => -27.1, 'lng' => -52.6], $resultado);
        $this->assertSame(1, $geocoder->chamadas);
    }

    /**
     * Rua+número sem resultado cai pra rua sem número, depois bairro, depois
     * só cidade — nessa ordem.
     */
    public function testDesceOsDegrausAteAcharAlgumResultado(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([
            'city=Chapecó&state=SC&street=Rua Teste 100' => null,
            'city=Chapecó&state=SC&street=Rua Teste'     => null,
            'q=Centro, Chapecó, SC, Brazil'              => ['lat' => -27.0, 'lng' => -52.5],
        ]);

        $resultado = $geocoder->geocode([
            'rua'    => 'Rua Teste',
            'numero' => '100',
            'bairro' => 'Centro',
            'cidade' => 'Chapecó',
            'estado' => 'SC',
        ]);

        $this->assertSame(['lat' => -27.0, 'lng' => -52.5], $resultado);
        $this->assertSame(3, $geocoder->chamadas);
        $this->assertSame(
            [
                'city=Chapecó&state=SC&street=Rua Teste 100',
                'city=Chapecó&state=SC&street=Rua Teste',
                'q=Centro, Chapecó, SC, Brazil',
            ],
            $geocoder->consultadas,
        );
    }

    /**
     * O bug que jogou metade do catálogo de um cliente em Goiás: sem a UF, o
     * Nominatim resolvia "São Miguel do Oeste" como "São Miguel do Araguaia".
     * A UF tem que estar em TODO degrau, não só no último.
     */
    public function testUfEntraEmTodosOsDegraus(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([]);

        $geocoder->geocode([
            'rua'    => 'Rua Duque de Caxias',
            'numero' => '402',
            'bairro' => 'Centro',
            'cidade' => 'São Miguel do Oeste',
            'estado' => 'SC',
        ]);

        $this->assertNotEmpty($geocoder->consultadas);

        foreach ($geocoder->consultadas as $consulta) {
            $this->assertStringContainsString('SC', $consulta, "degrau sem UF: {$consulta}");
        }
    }

    /**
     * Os degraus de rua e de cidade usam a consulta estruturada — ela acha a
     * rua onde o texto livre devolvia vazio, e quem devolve vazio desce até o
     * centro da cidade e empilha pin em cima de pin.
     */
    public function testDegrausDeRuaECidadeUsamConsultaEstruturada(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([]);

        $geocoder->geocode([
            'rua'    => 'Rua Teste',
            'numero' => '100',
            'cidade' => 'Chapecó',
            'estado' => 'SC',
        ]);

        $this->assertSame(
            [
                'city=Chapecó&state=SC&street=Rua Teste 100',
                'city=Chapecó&state=SC&street=Rua Teste',
                'city=Chapecó&state=SC',
            ],
            $geocoder->consultadas,
        );
    }

    /** Sem UF cadastrada o geocoder ainda funciona — só perde a desambiguação. */
    public function testSemUfConsultaSoComCidade(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([
            'city=Chapecó' => ['lat' => -27.0, 'lng' => -52.5],
        ]);

        $resultado = $geocoder->geocode(['cidade' => 'Chapecó']);

        $this->assertSame(['lat' => -27.0, 'lng' => -52.5], $resultado);
    }

    public function testSemNenhumDegrauEncontradoDevolveNull(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([]);

        $resultado = $geocoder->geocode(['cidade' => 'Chapecó', 'estado' => 'SC']);

        $this->assertNull($resultado);
    }

    public function testSemCidadeNaoConsultaNada(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([]);

        $resultado = $geocoder->geocode(['rua' => 'Rua Teste', 'bairro' => 'Centro']);

        $this->assertNull($resultado);
        $this->assertSame(0, $geocoder->chamadas);
    }

    /**
     * Endereço faz o mesmo conjunto de parâmetros se repetir entre degraus
     * (sem rua/bairro): a mesma consulta não pode ir duas vezes na mesma
     * chamada de geocode().
     */
    public function testMesmaConsultaEmDoisDegrausSoBateUmaVez(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([
            'city=Chapecó&state=SC' => ['lat' => -27.0, 'lng' => -52.5],
        ]);

        $geocoder->geocode(['cidade' => 'Chapecó', 'estado' => 'SC']);

        $this->assertSame(1, $geocoder->chamadas);
    }

    /**
     * O resultado (achado ou não) fica em cache — uma segunda chamada com o
     * mesmo endereço não bate a rede de novo. É o que faz uma rodada de
     * sync com centenas de imóveis não custar centenas de segundos (o
     * Nominatim exige no máximo 1 req/s).
     */
    public function testResultadoFicaEmCache(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([
            'city=Chapecó&state=SC' => ['lat' => -27.0, 'lng' => -52.5],
        ]);

        $primeira = $geocoder->geocode(['cidade' => 'Chapecó', 'estado' => 'SC']);
        $segunda  = $geocoder->geocode(['cidade' => 'Chapecó', 'estado' => 'SC']);

        $this->assertSame($primeira, $segunda);
        $this->assertSame(1, $geocoder->chamadas, 'segunda chamada devia vir do cache');
    }

    public function testResultadoNegativoTambemFicaEmCache(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([]);

        $geocoder->geocode(['cidade' => 'Chapecó', 'estado' => 'SC']);
        $geocoder->geocode(['cidade' => 'Chapecó', 'estado' => 'SC']);

        $this->assertSame(1, $geocoder->chamadas, '"não encontrado" também não pode bater a rede de novo');
    }

    /**
     * O degrau do bairro só aceita lugar de verdade. Sem isso, "Centro, São
     * Miguel do Oeste, SC" casava com a Epagri Cetresmo — um órgão público na
     * SC-163, a 7 km do centro — e 36 imóveis do catálogo de um cliente foram
     * parar lá.
     */
    public function testDegrauDoBairroSoAceitaLugar(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([]);

        $geocoder->geocode([
            'rua'    => 'Rua Teste',
            'numero' => '100',
            'bairro' => 'Centro',
            'cidade' => 'São Miguel do Oeste',
            'estado' => 'SC',
        ]);

        // rua+numero, rua, bairro, cidade — só o do bairro restringe a classe.
        $this->assertSame(
            [null, null, ['place', 'boundary'], null],
            $geocoder->filtros,
        );
    }

    /** Filtro diferente na mesma consulta não pode reaproveitar a entrada de cache. */
    public function testFiltroDeClasseEntraNaChaveDoCache(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([
            'city=Chapecó&state=SC' => ['lat' => -27.0, 'lng' => -52.5],
        ]);

        $geocoder->geocode(['bairro' => 'Centro', 'cidade' => 'Chapecó', 'estado' => 'SC']);
        $chamadasApos = $geocoder->chamadas;

        $geocoder->geocode(['bairro' => 'Centro', 'cidade' => 'Chapecó', 'estado' => 'SC']);

        $this->assertSame($chamadasApos, $geocoder->chamadas, 'a segunda rodada devia vir toda do cache');
    }

    /**
     * A resposta real do Nominatim para "Centro, São Miguel do Oeste, SC":
     * cinco casamentos de texto, NENHUM deles um bairro. O primeiro é a Epagri
     * Cetresmo, e foi ele que levou 36 imóveis pra SC-163.
     */
    public function testSelecaoRecusaOrgaoPublicoEComercioNoDegrauDoBairro(): void
    {
        $geocoder = new SelecaoExposta();

        $respostaReal = [
            ['class' => 'office',  'type' => 'government',     'lat' => '-26.7867573', 'lon' => '-53.5057388'],
            ['class' => 'amenity', 'type' => 'clinic',         'lat' => '-26.7379000', 'lon' => '-53.5190100'],
            ['class' => 'amenity', 'type' => 'doctors',        'lat' => '-26.7312000', 'lon' => '-53.5147200'],
            ['class' => 'amenity', 'type' => 'driving_school', 'lat' => '-26.7378400', 'lon' => '-53.5177800'],
            ['class' => 'amenity', 'type' => 'school',         'lat' => '-26.7944300', 'lon' => '-53.5089100'],
        ];

        $this->assertNull(
            $geocoder->escolher($respostaReal, ['place', 'boundary']),
            'nenhum é bairro: tem de descer pro degrau da cidade',
        );
    }

    /** Bairro que existe no OSM volta como place/suburb — esse serve. */
    public function testSelecaoAceitaBairroDeVerdade(): void
    {
        $geocoder = new SelecaoExposta();

        $resultado = $geocoder->escolher(
            [['class' => 'place', 'type' => 'suburb', 'lat' => '-26.7301648', 'lon' => '-53.5395312']],
            ['place', 'boundary'],
        );

        $this->assertSame(['lat' => -26.7301648, 'lng' => -53.5395312], $resultado);
    }

    /** Com o lugar depois do ruído, ele é encontrado em vez de virar "não achei". */
    public function testSelecaoPulaORuidoEAchaOLugarMaisAbaixo(): void
    {
        $geocoder = new SelecaoExposta();

        $resultado = $geocoder->escolher(
            [
                ['class' => 'amenity', 'type' => 'school',  'lat' => '-1.0', 'lon' => '-1.0'],
                ['class' => 'place',   'type' => 'quarter', 'lat' => '-26.7', 'lon' => '-53.5'],
            ],
            ['place', 'boundary'],
        );

        $this->assertSame(['lat' => -26.7, 'lng' => -53.5], $resultado);
    }

    /** Sem filtro (degraus de rua e cidade) o primeiro resultado vale. */
    public function testSelecaoSemFiltroPegaOPrimeiro(): void
    {
        $geocoder = new SelecaoExposta();

        $resultado = $geocoder->escolher(
            [['class' => 'office', 'type' => 'government', 'lat' => '-26.78', 'lon' => '-53.50']],
            null,
        );

        $this->assertSame(['lat' => -26.78, 'lng' => -53.50], $resultado);
    }

    /** Resposta vazia ou malformada não pode explodir — o geocoder é fail-open. */
    public function testSelecaoTolerraRespostaEstranha(): void
    {
        $geocoder = new SelecaoExposta();

        $this->assertNull($geocoder->escolher([], null));
        $this->assertNull($geocoder->escolher('nao e array', null));
        $this->assertNull($geocoder->escolher([['sem' => 'coordenada']], null));
    }

    /**
     * O Simob grava a esquina e o apartamento DENTRO do campo de rua. O
     * Nominatim nao resolve nada disso: 54 imoveis desciam a escada inteira e
     * paravam no centro da cidade, todos no mesmo pin. Os casos abaixo sao
     * literais do catalogo em producao.
     *
     * @dataProvider enderecosDoSimob
     */
    public function testIsolaOLogradouroDaDescricao(string $cru, string $esperado): void
    {
        $geocoder = new SelecaoExposta();

        $this->assertSame($esperado, $geocoder->isolar($cru));
    }

    public static function enderecosDoSimob(): array
    {
        return [
            'esquina com virgula, apto e box' => [
                'RUA LA SALLE, ESQUINA COM A RUA MARQUES DO HERVAL - APTO 2301 | BOX 41, 42 E 43',
                'RUA LA SALLE',
            ],
            'esquina sem virgula' => [
                'RUA LA SALLE ESQUINA COM RUA RUI BARBOSA - APTO 1001',
                'RUA LA SALLE',
            ],
            'comeca com ESQUINA DAS RUAS' => [
                'ESQUINA DAS RUAS MARCÍLIO DIAS COM RUA ALMIRANTE BARROSO - APTO 604',
                'MARCÍLIO DIAS',
            ],
            'avenida com torre' => [
                'AVENIDA SALGADO FILHO ESQUINA COM A RUA ALMIRANTE TAMANDARE E SETE DE SETEMBRO - APTO 502 | TORRE NORTE',
                'AVENIDA SALGADO FILHO',
            ],
            'tipo e bairro colados' => ['Rua La Sale - Casa - Centro', 'Rua La Sale'],
            'lote' => ['RUA PROJETADA A - LOTE 30', 'RUA PROJETADA A'],
            'rua limpa fica intacta' => [
                'RUA VEREADOR NERCI ZINO GRACIOLLI',
                'RUA VEREADOR NERCI ZINO GRACIOLLI',
            ],
            'espaco repetido colapsa' => ['Rua  Rudolfo   Spier', 'Rua Rudolfo Spier'],
            'vazio' => ['', ''],
            'so pontuacao' => [' - | ', ''],
        ];
    }

    /**
     * O degrau limpo vem DEPOIS do texto original: quando o original resolve
     * ele e mais especifico e o limpo nem chega a ser consultado.
     */
    public function testDegrauLimpoVemDepoisDoOriginal(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([]);

        $geocoder->geocode([
            'rua'    => 'RUA LA SALLE ESQUINA COM RUA RUI BARBOSA - APTO 1001',
            'numero' => '100',
            'bairro' => 'Centro',
            'cidade' => 'São Miguel do Oeste',
            'estado' => 'SC',
        ]);

        $this->assertSame([
            'city=São Miguel do Oeste&state=SC&street=RUA LA SALLE ESQUINA COM RUA RUI BARBOSA - APTO 1001 100',
            'city=São Miguel do Oeste&state=SC&street=RUA LA SALLE ESQUINA COM RUA RUI BARBOSA - APTO 1001',
            'city=São Miguel do Oeste&state=SC&street=RUA LA SALLE 100',
            'city=São Miguel do Oeste&state=SC&street=RUA LA SALLE',
            'q=Centro, São Miguel do Oeste, SC, Brazil',
            'city=São Miguel do Oeste&state=SC',
        ], $geocoder->consultadas);
    }

    /** Rua que ja esta limpa nao pode gerar degrau repetido e gastar rede a toa. */
    public function testRuaLimpaNaoGeraDegrauDuplicado(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([]);

        $geocoder->geocode([
            'rua'    => 'Rua Rudolfo Spier',
            'cidade' => 'São Miguel do Oeste',
            'estado' => 'SC',
        ]);

        $this->assertSame([
            'city=São Miguel do Oeste&state=SC&street=Rua Rudolfo Spier',
            'city=São Miguel do Oeste&state=SC',
        ], $geocoder->consultadas);
    }

    /** A caixa do endereço não pode gerar duas entradas de cache pra mesma consulta. */
    public function testCacheIgnoraCaixaDoEndereco(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([
            'city=Chapecó&state=SC' => ['lat' => -27.0, 'lng' => -52.5],
        ]);

        $geocoder->geocode(['cidade' => 'Chapecó', 'estado' => 'SC']);
        $geocoder->geocode(['cidade' => 'CHAPECÓ', 'estado' => 'sc']);

        $this->assertSame(1, $geocoder->chamadas);
    }
}

/** Dublê: troca a chamada de rede por um mapa consulta => resultado fixo. */
final class RespostaFixaNominatimGeocoder extends NominatimGeocoder
{
    public int $chamadas = 0;

    /** @var list<string> na ordem em que os degraus foram consultados */
    public array $consultadas = [];

    /** @param array<string, array{lat:float,lng:float}|null> $respostas */
    public function __construct(private array $respostas)
    {
    }

    /** @var list<list<string>|null> o filtro de classe recebido em cada degrau */
    public array $filtros = [];

    protected function consultar(array $params, ?array $aceita = null): ?array
    {
        $this->chamadas++;
        $this->filtros[] = $aceita;

        // Forma legível pros testes: "city=X&state=Y&street=Z", chaves em
        // ordem alfabética (mesma ordem que a chave de cache usa).
        ksort($params);
        $assinatura          = urldecode(http_build_query($params));
        $this->consultadas[] = $assinatura;

        return $this->respostas[$assinatura] ?? null;
    }
}

/** Expõe selecionar() — é a regra que impede um órgão público de virar bairro. */
final class SelecaoExposta extends NominatimGeocoder
{
    public function escolher($data, ?array $aceita): ?array
    {
        return $this->selecionar($data, $aceita);
    }

    public function isolar(string $rua): string
    {
        return $this->isolarLogradouro($rua);
    }
}
