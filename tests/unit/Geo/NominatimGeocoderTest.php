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
            'Rua Teste 100, Centro, Chapecó, Brazil' => ['lat' => -27.1, 'lng' => -52.6],
        ]);

        $resultado = $geocoder->geocode([
            'rua'    => 'Rua Teste',
            'numero' => '100',
            'bairro' => 'Centro',
            'cidade' => 'Chapecó',
        ]);

        $this->assertSame(['lat' => -27.1, 'lng' => -52.6], $resultado);
        $this->assertSame(1, $geocoder->chamadas);
    }

    /**
     * "Rua Teste, 100" sem resultado cai pro degrau seguinte, sem número:
     * "Rua Teste, Centro, Chapecó, Brazil" — e assim por diante até só cidade.
     */
    public function testDegraiaAteAcharAlgumResultado(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([
            'Rua Teste 100, Centro, Chapecó, Brazil' => null,
            'Rua Teste, Centro, Chapecó, Brazil'      => null,
            'Centro, Chapecó, Brazil'                 => ['lat' => -27.0, 'lng' => -52.5],
        ]);

        $resultado = $geocoder->geocode([
            'rua'    => 'Rua Teste',
            'numero' => '100',
            'bairro' => 'Centro',
            'cidade' => 'Chapecó',
        ]);

        $this->assertSame(['lat' => -27.0, 'lng' => -52.5], $resultado);
        $this->assertSame(3, $geocoder->chamadas);
    }

    public function testSemNenhumDegrauEncontradoDevolveNull(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([
            'Chapecó, Brazil' => null,
        ]);

        $resultado = $geocoder->geocode(['cidade' => 'Chapecó']);

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
     * Endereço faz o mesmo texto se repetir entre degraus (sem rua/bairro): a
     * mesma string não pode ser consultada duas vezes na mesma chamada de
     * geocode().
     */
    public function testMesmaConsultaEmDoisDegrausSoBateUmaVez(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([
            'Chapecó, Brazil' => ['lat' => -27.0, 'lng' => -52.5],
        ]);

        $geocoder->geocode(['cidade' => 'Chapecó']);

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
            'Chapecó, Brazil' => ['lat' => -27.0, 'lng' => -52.5],
        ]);

        $primeira  = $geocoder->geocode(['cidade' => 'Chapecó']);
        $segunda   = $geocoder->geocode(['cidade' => 'Chapecó']);

        $this->assertSame($primeira, $segunda);
        $this->assertSame(1, $geocoder->chamadas, 'segunda chamada devia vir do cache');
    }

    public function testResultadoNegativoTambemFicaEmCache(): void
    {
        $geocoder = new RespostaFixaNominatimGeocoder([
            'Chapecó, Brazil' => null,
        ]);

        $geocoder->geocode(['cidade' => 'Chapecó']);
        $geocoder->geocode(['cidade' => 'Chapecó']);

        $this->assertSame(1, $geocoder->chamadas, '"não encontrado" também não pode bater a rede de novo');
    }
}

/** Dublê: troca a chamada de rede por um mapa consulta => resultado fixo. */
final class RespostaFixaNominatimGeocoder extends NominatimGeocoder
{
    public int $chamadas = 0;

    /** @param array<string, array{lat:float,lng:float}|null> $respostas */
    public function __construct(private array $respostas)
    {
    }

    protected function consultar(string $query): ?array
    {
        $this->chamadas++;

        return $this->respostas[$query] ?? null;
    }
}
