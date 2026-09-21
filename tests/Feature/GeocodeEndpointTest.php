<?php

namespace Tests\Feature;

use App\Libraries\Geo\GeocoderInterface;
use Config\Services;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * O endpoint que o formulário de imóvel usa para localizar o endereço.
 *
 * Existe para que o navegador PARE de ter a sua própria escada de consultas.
 * Havia duas implementações da mesma lógica e a do navegador era a antiga: sem
 * UF em nenhum degrau (o bug que mandou 52 imóveis para Goiás) e sem o filtro
 * de classe no degrau de bairro (36 foram parar num órgão público na rodovia).
 * Consertar o servidor não consertava o cadastro manual.
 */
final class GeocodeEndpointTest extends HabitawebTestCase
{
    private const ROTA = 'admin/properties/geocode';

    /** Mesmo padrão de PromotionQuotaRouteTest: o POST precisa do token. */
    private function withCsrf(array $data = []): array
    {
        return array_merge([csrf_token() => csrf_hash()], $data);
    }

    private function usarGeocoder(?array $resultado): GeocoderDublê
    {
        $fake = new GeocoderDublê($resultado);
        Services::injectMock('geocoder', $fake);

        return $fake;
    }

    public function testDevolveACoordenadaDoGeocoder(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->usarGeocoder(['lat' => -26.7278977, 'lng' => -53.5176689]);

        $r = $this->actingAs($tenant['user'])->post(self::ROTA, $this->withCsrf([
            'rua'    => 'Rua Rudolfo Spier',
            'bairro' => 'Salete',
            'cidade' => 'São Miguel do Oeste',
            'estado' => 'SC',
        ]));

        $r->assertOK();
        $corpo = json_decode($r->getJSON(), true);
        $this->assertTrue($corpo['success']);
        $this->assertSame(-26.7278977, $corpo['latitude']);
        $this->assertSame(-53.5176689, $corpo['longitude']);
    }

    /**
     * A UF é o que separa São Miguel do Oeste/SC de São Miguel do Araguaia/GO.
     * Se o endpoint não repassar, o conserto do geocoder não vale para o
     * cadastro manual.
     */
    public function testRepassaAUfParaOGeocoder(): void
    {
        $tenant = (new TenantFactory())->create();
        $fake   = $this->usarGeocoder(['lat' => -26.7, 'lng' => -53.5]);

        $this->actingAs($tenant['user'])->post(self::ROTA, $this->withCsrf([
            'rua'    => 'Rua Teste',
            'numero' => '100',
            'bairro' => 'Centro',
            'cidade' => 'São Miguel do Oeste',
            'estado' => 'SC',
        ]));

        $this->assertSame('SC', $fake->recebido['estado'] ?? null);
        $this->assertSame('São Miguel do Oeste', $fake->recebido['cidade'] ?? null);
    }

    /**
     * Endereço rural ou de loteamento novo não existe no OpenStreetMap. Não é
     * erro: é o caminho previsto para o usuário posicionar o pino à mão — mas
     * precisa AVISAR, porque silêncio é o que faz o corretor salvar sem
     * coordenada sem saber.
     */
    public function testQuandoNaoAchaDevolveAvisoEmVezDeSilencio(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->usarGeocoder(null);

        $r = $this->actingAs($tenant['user'])->post(self::ROTA, $this->withCsrf([
            'rua'    => 'LINHA FÁTIMA',
            'cidade' => 'São Miguel do Oeste',
            'estado' => 'SC',
        ]));

        $r->assertOK();
        $corpo = json_decode($r->getJSON(), true);
        $this->assertFalse($corpo['success']);
        $this->assertNotEmpty($corpo['message']);
        $this->assertStringContainsStringIgnoringCase('pino', $corpo['message']);
    }

    /** Sem cidade não há o que consultar — e não se gasta requisição no Nominatim. */
    public function testSemCidadeNaoChamaOGeocoder(): void
    {
        $tenant = (new TenantFactory())->create();
        $fake   = $this->usarGeocoder(['lat' => -26.7, 'lng' => -53.5]);

        $r = $this->actingAs($tenant['user'])->post(self::ROTA, $this->withCsrf(['rua' => 'Rua Teste']));

        $r->assertOK();
        $this->assertFalse(json_decode($r->getJSON(), true)['success']);
        $this->assertSame(0, $fake->chamadas);
    }

    /**
     * O endpoint não pode virar proxy aberto de Nominatim: sem sessão de
     * admin, ninguém entra.
     */
    public function testExigeSessaoDeAdmin(): void
    {
        $fake = $this->usarGeocoder(['lat' => -26.7, 'lng' => -53.5]);

        // Token de CSRF válido de propósito: o que tem de barrar aqui é a
        // falta de sessão de admin, não o CSRF.
        $r = $this->post(self::ROTA, $this->withCsrf(['cidade' => 'São Miguel do Oeste']));

        $this->assertNotSame(200, $r->response()->getStatusCode());
        $this->assertSame(0, $fake->chamadas, 'anônimo não pode gastar consulta');
    }
}

/** Dublê de geocoder: nunca toca em rede e guarda o que recebeu. */
final class GeocoderDublê implements GeocoderInterface
{
    public int $chamadas = 0;
    public array $recebido = [];

    public function __construct(private ?array $resultado)
    {
    }

    public function geocode(array $endereco): ?array
    {
        $this->chamadas++;
        $this->recebido = $endereco;

        return $this->resultado;
    }
}
