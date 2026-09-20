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

    protected function consultar(array $params): ?array
    {
        $this->chamadas++;

        // Forma legível pros testes: "city=X&state=Y&street=Z", chaves em
        // ordem alfabética (mesma ordem que a chave de cache usa).
        ksort($params);
        $assinatura          = urldecode(http_build_query($params));
        $this->consultadas[] = $assinatura;

        return $this->respostas[$assinatura] ?? null;
    }
}
