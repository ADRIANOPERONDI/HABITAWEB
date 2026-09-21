<?php

namespace Tests\Feature;

use App\Libraries\Geo\GeocoderInterface;
use App\Models\PropertyModel;
use App\Services\PropertyService;
use Config\Services;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * O pino posto à mão é a ÚNICA forma de posicionar endereço rural ou de
 * loteamento novo — `RUA PROJETADA C`, `LINHA FÁTIMA` e companhia não existem
 * no OpenStreetMap, testei um a um. Se o lote ou o sync sobrescrevem esse
 * ajuste, a informação some e não volta.
 *
 * Estes testes travam exatamente isso.
 */
final class CoordenadaManualTest extends HabitawebTestCase
{
    private int $accountId;
    private PropertyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountId = (new TenantFactory())->create()['account']->id;
        $this->service   = new PropertyService();
    }

    private function usarGeocoder(?array $resultado): void
    {
        Services::injectMock('geocoder', new class ($resultado) implements GeocoderInterface {
            public function __construct(private ?array $r)
            {
            }

            public function geocode(array $e): ?array
            {
                return $this->r;
            }
        });
    }

    private function base(array $overrides = []): array
    {
        return array_merge([
            'account_id'   => $this->accountId,
            'titulo'       => 'Imóvel ' . bin2hex(random_bytes(3)),
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'casa',
            'cidade'       => 'São Miguel do Oeste',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
            'preco'        => 300000,
        ], $overrides);
    }

    private function coordenada(int $id): array
    {
        $p = (new PropertyModel())->find($id);

        return [(float) $p->latitude, (float) $p->longitude];
    }

    /** Arrastar o pino e salvar marca a coordenada como manual. */
    public function testSalvarComCoordenadaNovaMarcaComoManual(): void
    {
        $r = $this->service->trySaveProperty(
            $this->base(['latitude' => -26.7318435, 'longitude' => -53.5267074]),
            null,
            true,
        );

        $this->assertTrue($r['success'], json_encode($r));
        $this->assertTrue($r['data']->coordenadas_manuais);
    }

    /**
     * O cast tem de estar no MODEL: o Postgres devolve 'f', que é truthy em
     * PHP. Sem isso todo imóvel seria lido como manual e o lote nunca mais
     * tocaria em nada — falhando em silêncio.
     */
    public function testImovelSemAjusteVemFalseDeVerdade(): void
    {
        $id = (int) (new PropertyModel())->insert($this->base(['latitude' => -26.7, 'longitude' => -53.5]), true);

        $this->assertFalse((new PropertyModel())->find($id)->coordenadas_manuais);
    }

    /** A garantia que o pedido inteiro depende. */
    public function testCoordenadaManualSobreviveAoLote(): void
    {
        $id = (int) (new PropertyModel())->insert(
            $this->base(['latitude' => -26.6131544, 'longitude' => -53.7341625, 'coordenadas_manuais' => true]),
            true,
        );
        // Um segundo imóvel na mesma coordenada faria o lote considerar os dois
        // "empilhados" — sem a marca, ambos seriam regeocodificados.
        (new PropertyModel())->insert($this->base(['latitude' => -26.6131544, 'longitude' => -53.7341625]), true);

        $this->usarGeocoder(['lat' => -1.0, 'lng' => -1.0]);
        command('imoveis:geocodificar --force');

        $this->assertSame([-26.6131544, -53.7341625], $this->coordenada($id));
    }

    /** E sobrevive também à passada do cron. */
    public function testCoordenadaManualSobreviveAoCron(): void
    {
        $id = (int) (new PropertyModel())->insert(
            $this->base(['latitude' => -26.61, 'longitude' => -53.73, 'coordenadas_manuais' => true]),
            true,
        );

        $this->usarGeocoder(['lat' => -1.0, 'lng' => -1.0]);
        command('imoveis:geocodificar --apenas-novos --force');

        $this->assertSame([-26.61, -53.73], $this->coordenada($id));
    }

    /**
     * O cadastro manual e a API de parceiro não geocodificam no caminho da
     * requisição — quem cobre os dois é o cron.
     */
    public function testCronGeocodificaImovelSalvoSemCoordenada(): void
    {
        $r = $this->service->trySaveProperty($this->base(), null, true);
        $this->assertTrue($r['success'], json_encode($r));
        $id = (int) $r['data']->id;

        $this->assertNull((new PropertyModel())->find($id)->latitude);

        $this->usarGeocoder(['lat' => -26.7278977, 'lng' => -53.5176689]);
        command('imoveis:geocodificar --apenas-novos --force');

        $this->assertSame([-26.7278977, -53.5176689], $this->coordenada($id));
    }

    /**
     * Modo cron não pode mexer em quem já tem coordenada: senão ficaria
     * regeocodificando para sempre os imóveis empilhados, queimando a cota.
     */
    public function testCronNaoTocaEmQuemJaTemCoordenada(): void
    {
        $a = (int) (new PropertyModel())->insert($this->base(['latitude' => -26.72, 'longitude' => -53.51]), true);
        $b = (int) (new PropertyModel())->insert($this->base(['latitude' => -26.72, 'longitude' => -53.51]), true);

        $this->usarGeocoder(['lat' => -1.0, 'lng' => -1.0]);
        command('imoveis:geocodificar --apenas-novos --force');

        $this->assertSame([-26.72, -53.51], $this->coordenada($a));
        $this->assertSame([-26.72, -53.51], $this->coordenada($b));
    }

    /**
     * No cron, lista vazia é o resultado ESPERADO. A mensagem tem de dizer
     * isso: "nenhum imóvel com cidade cadastrada" num log de minuto em minuto
     * faria qualquer um achar que o catálogo sumiu.
     */
    public function testCronSemNadaAFazerDizQueEstaTudoGeocodificado(): void
    {
        (new PropertyModel())->insert($this->base(['latitude' => -26.72, 'longitude' => -53.51]), true);

        // CLI::write escreve direto no STDOUT, então command() devolve string
        // vazia; o filtro do framework é o que captura.
        \CodeIgniter\Test\Filters\CITestStreamFilter::registration();
        \CodeIgniter\Test\Filters\CITestStreamFilter::addOutputFilter();
        command('imoveis:geocodificar --apenas-novos --force');
        $saida = \CodeIgniter\Test\Filters\CITestStreamFilter::$buffer;
        \CodeIgniter\Test\Filters\CITestStreamFilter::removeOutputFilter();

        $this->assertStringContainsString('já têm coordenada', $saida);
        $this->assertStringNotContainsString('Nenhum imóvel com cidade cadastrada', $saida);
    }

    /** A marca é derivada — aceitar do payload congelaria o imóvel de graça. */
    public function testMarcaNaoPodeVirDoPayloadDaApi(): void
    {
        $r = $this->service->trySaveProperty(
            $this->base(['coordenadas_manuais' => true]),
            null,
            false,
        );

        $this->assertTrue($r['success'], json_encode($r));
        $this->assertFalse($r['data']->coordenadas_manuais);
    }

    /**
     * Coordenada saiu de MANAGED_FIELDS: o painel para de descartar em silêncio
     * o pino arrastado num imóvel espelhado da integração.
     */
    public function testCoordenadaNaoEeMaisCampoGerenciadoPelaIntegracao(): void
    {
        $this->assertNotContains('latitude', \App\Services\IntegrationService::MANAGED_FIELDS);
        $this->assertNotContains('longitude', \App\Services\IntegrationService::MANAGED_FIELDS);
    }
}
