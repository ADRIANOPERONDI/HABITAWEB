<?php

namespace Tests\Feature;

use App\Libraries\Geo\GeocoderInterface;
use App\Models\PropertyModel;
use Config\Services;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * `spark imoveis:geocodificar` — o conserto das coordenadas já gravadas.
 *
 * Existe porque IntegrationSyncService::geocodeIfNeeded() sai cedo quando o
 * imóvel já tem lat/lng: rodar o sync de novo não corrige uma linha sequer.
 * Um cliente de Santa Catarina teve 52 imóveis apontando para Goiás e só um
 * backfill os tira de lá.
 *
 * A regra mais delicada é a seleção: mexer em imóvel de coordenada boa é tão
 * ruim quanto deixar o errado no lugar, porque cada consulta custa ~1s de
 * throttle do Nominatim e pode PIORAR um endereço que já estava certo.
 */
final class GeocodePropertiesCommandTest extends HabitawebTestCase
{
    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountId = (new TenantFactory())->create()['account']->id;
    }

    private function criarImovel(array $overrides = []): int
    {
        return (int) (new PropertyModel())->insert(array_merge([
            'account_id'   => $this->accountId,
            'titulo'       => 'Imóvel ' . bin2hex(random_bytes(3)),
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'casa',
            'cidade'       => 'São Miguel do Oeste',
            'estado'       => 'SC',
            'bairro'       => 'Salete',
            'status'       => 'ACTIVE',
            'preco'        => 300000,
        ], $overrides), true);
    }

    private function usarGeocoder(?array $resultado): GeocoderContador
    {
        $fake = new GeocoderContador($resultado);
        Services::injectMock('geocoder', $fake);

        return $fake;
    }

    private function coordenada(int $id): array
    {
        $imovel = (new PropertyModel())->find($id);

        return [$imovel->latitude === null ? null : (float) $imovel->latitude,
            $imovel->longitude === null ? null : (float) $imovel->longitude];
    }

    /**
     * O caso do cliente real: coordenada em Goiás num imóvel cadastrado em SC.
     * A caixa da UF é o que enxerga isso — a coordenada é única, então a regra
     * de "empilhada" sozinha deixaria passar.
     */
    public function testRegeocodificaCoordenadaForaDaUfDoImovel(): void
    {
        $id = $this->criarImovel(['latitude' => -13.2729849, 'longitude' => -50.1615792]);
        $this->usarGeocoder(['lat' => -26.7301648, 'lng' => -53.5395312]);

        command('imoveis:geocodificar --force');

        $this->assertSame([-26.7301648, -53.5395312], $this->coordenada($id));
    }

    /** Coordenada idêntica em dois imóveis = os dois caíram no mesmo centroide. */
    public function testRegeocodificaCoordenadasEmpilhadas(): void
    {
        $a = $this->criarImovel(['latitude' => -26.8246854, 'longitude' => -53.5017586]);
        $b = $this->criarImovel(['latitude' => -26.8246854, 'longitude' => -53.5017586]);
        $this->usarGeocoder(['lat' => -26.73, 'lng' => -53.52]);

        command('imoveis:geocodificar --force');

        $this->assertSame([-26.73, -53.52], $this->coordenada($a));
        $this->assertSame([-26.73, -53.52], $this->coordenada($b));
    }

    public function testGeocodificaImovelSemCoordenada(): void
    {
        $id = $this->criarImovel(['latitude' => null, 'longitude' => null]);
        $this->usarGeocoder(['lat' => -26.73, 'lng' => -53.52]);

        command('imoveis:geocodificar --force');

        $this->assertSame([-26.73, -53.52], $this->coordenada($id));
    }

    /**
     * A garantia que impede o comando de estragar o catálogo: coordenada única
     * e dentro da UF é endereço resolvido de verdade — não se toca, e nem se
     * gasta consulta com ela.
     */
    public function testNaoTocaEmCoordenadaUnicaDentroDaUf(): void
    {
        $id   = $this->criarImovel(['latitude' => -26.7318435, 'longitude' => -53.5267074]);
        $fake = $this->usarGeocoder(['lat' => -1.0, 'lng' => -1.0]);

        command('imoveis:geocodificar --force');

        $this->assertSame([-26.7318435, -53.5267074], $this->coordenada($id));
        $this->assertSame(0, $fake->calls, 'não pode gastar consulta em coordenada boa');
    }

    public function testDryRunNaoGravaNada(): void
    {
        $id = $this->criarImovel(['latitude' => -13.2729849, 'longitude' => -50.1615792]);
        $this->usarGeocoder(['lat' => -26.73, 'lng' => -53.52]);

        command('imoveis:geocodificar --dry-run');

        $this->assertSame([-13.2729849, -50.1615792], $this->coordenada($id));
    }

    /**
     * Fail-open: Nominatim sem resposta não pode apagar a coordenada que já
     * estava lá — errada, mas é o que o mapa tem até a próxima tentativa.
     */
    public function testSemResultadoMantemCoordenadaAnterior(): void
    {
        $id = $this->criarImovel(['latitude' => -13.2729849, 'longitude' => -50.1615792]);
        $this->usarGeocoder(null);

        command('imoveis:geocodificar --force');

        $this->assertSame([-13.2729849, -50.1615792], $this->coordenada($id));
    }

    /** Imóvel sem cidade não tem o que consultar — o geocoder nem é chamado. */
    public function testImovelSemCidadeEIgnorado(): void
    {
        $this->criarImovel(['cidade' => '', 'latitude' => null, 'longitude' => null]);
        $fake = $this->usarGeocoder(['lat' => -26.73, 'lng' => -53.52]);

        command('imoveis:geocodificar --force');

        $this->assertSame(0, $fake->calls);
    }

    public function testFiltroDeContaIgnoraOutroTenant(): void
    {
        $outraConta = (new TenantFactory())->create()['account']->id;
        $alheio     = $this->criarImovel(['account_id' => $outraConta, 'latitude' => -13.27, 'longitude' => -50.16]);
        $meu        = $this->criarImovel(['latitude' => -13.27, 'longitude' => -50.16]);

        $this->usarGeocoder(['lat' => -26.73, 'lng' => -53.52]);

        command('imoveis:geocodificar --force --conta ' . $this->accountId);

        $this->assertSame([-26.73, -53.52], $this->coordenada($meu));
        $this->assertSame([-13.27, -50.16], $this->coordenada($alheio), 'conta não filtrada foi alterada');
    }
}

/** Dublê de geocoder que conta chamadas — nunca toca em rede. */
final class GeocoderContador implements GeocoderInterface
{
    public int $calls = 0;

    public function __construct(private ?array $resultado)
    {
    }

    public function geocode(array $endereco): ?array
    {
        $this->calls++;

        return $this->resultado;
    }
}
