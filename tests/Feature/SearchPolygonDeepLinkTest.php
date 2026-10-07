<?php

namespace Tests\Feature;

use App\Models\PropertyModel;
use App\Services\PublicPropertyVisibilityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * A busca passa a aceitar o polígono pela URL (`/imoveis/mapa?polygon=`),
 * que é como o mapa da home entrega a área desenhada. O valor volta para a
 * página no input oculto que alimenta o fetch da API, então só o que
 * `PolygonFilter` reconhece como polígono pode ser ecoado — e a API do mapa,
 * que já filtrava por polígono sem nenhum teste, passa pela mesma borda.
 */
final class SearchPolygonDeepLinkTest extends HabitawebTestCase
{
    private const TRIANGULO = '[[-46.7,-23.6],[-46.5,-23.6],[-46.5,-23.4]]';

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
    }

    protected function tearDown(): void
    {
        cache()->clean();
        parent::tearDown();
    }

    public function testPoligonoDaUrlPreencheOFiltroDoMapa(): void
    {
        $response = $this->get('imoveis/mapa', ['polygon' => self::TRIANGULO]);

        $response->assertOK();
        $this->assertStringContainsString('id="inputPolygon" value="' . self::TRIANGULO . '"', $response->getBody());
    }

    public function testPoligonoVoltaNormalizadoParaAPagina(): void
    {
        $response = $this->get('imoveis/mapa', ['polygon' => ' [[ -46.70, "-23.6" ], [-46.5,-23.60], [-46.5,-23.4]] ']);

        $response->assertOK();
        $this->assertStringContainsString('id="inputPolygon" value="' . self::TRIANGULO . '"', $response->getBody());
    }

    /** @return array<string, array{0: string}> */
    public static function poligonosInvalidosProvider(): array
    {
        return [
            'texto'         => ['abc'],
            'dois pontos'   => ['[[-46.7,-23.6],[-46.5,-23.6]]'],
            'fora da faixa' => ['[[-190,-23.6],[-46.5,-23.6],[-46.5,-23.4]]'],
            'html'          => ['<script>alert(1)</script>'],
        ];
    }

    /** @dataProvider poligonosInvalidosProvider */
    public function testPoligonoInvalidoNaoEEcoado(string $polygon): void
    {
        $response = $this->get('imoveis/mapa', ['polygon' => $polygon]);

        $response->assertOK();
        $this->assertStringContainsString('id="inputPolygon" value=""', $response->getBody());
        $this->assertStringNotContainsString('<script>alert(1)</script>', $response->getBody());
    }

    public function testApiDoMapaFiltraPelaAreaDoPoligono(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;
        $dentro = $this->imovel($accountId, 'Dentro da Area', -23.55, -46.63); // centro de SP, dentro do quadrado
        $fora   = $this->imovel($accountId, 'Fora da Area', -22.90, -43.20);   // Rio, fora
        PublicPropertyVisibilityService::invalidateCaches();

        $quadrado = '[[-46.8,-23.7],[-46.4,-23.7],[-46.4,-23.4],[-46.8,-23.4]]';
        $response = $this->get('api/imoveis/mapa', ['polygon' => $quadrado, 'per_page' => 36]);

        $json = $this->decodeJson($response);
        $ids  = array_map('intval', array_column($json['map_data'] ?? [], 'id'));

        $this->assertContains($dentro, $ids, 'Imóvel dentro do polígono precisa vir no mapa.');
        $this->assertNotContains($fora, $ids, 'Imóvel fora do polígono não pode vir no mapa.');
        $this->assertStringContainsString('Dentro da Area', $json['list_html']);
        $this->assertStringNotContainsString('Fora da Area', $json['list_html']);
    }

    public function testApiDoMapaIgnoraPoligonoInvalidoEmVezDeQuebrar(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;
        $id = $this->imovel($accountId, 'Qualquer Lugar', -23.55, -46.63);
        PublicPropertyVisibilityService::invalidateCaches();

        $response = $this->get('api/imoveis/mapa', ['polygon' => 'abc', 'per_page' => 36]);

        $json = $this->decodeJson($response);
        $this->assertTrue($json['success']);
        $this->assertContains($id, array_map('intval', array_column($json['map_data'] ?? [], 'id')));
    }

    /**
     * `TestResponse::getBody()` não existe e cai no DOMParser (devolve o JSON
     * reserializado como HTML) — por isso `getJSON()`, como nos outros testes
     * de API do projeto. Falha mostrando status e início do corpo quando a
     * resposta não é o JSON esperado.
     */
    private function decodeJson($response): array
    {
        $response->assertOK();
        $body = (string) $response->getJSON();
        $json = json_decode($body, true);
        $this->assertIsArray($json, 'Resposta não é JSON. status=' . $response->response()->getStatusCode() . ' body=' . substr($body, 0, 400));

        return $json;
    }

    private function imovel(int $accountId, string $titulo, float $lat, float $lng): int
    {
        $model = new PropertyModel();
        $model->insert([
            'account_id'   => $accountId,
            'titulo'       => $titulo,
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'apartamento',
            'cidade'       => 'São Paulo',
            'bairro'       => 'Centro',
            'preco'        => 500000,
            'latitude'     => $lat,
            'longitude'    => $lng,
            'status'       => 'ACTIVE',
        ]);

        return (int) $model->getInsertID();
    }
}
