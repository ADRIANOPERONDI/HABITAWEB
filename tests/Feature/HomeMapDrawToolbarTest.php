<?php

namespace Tests\Feature;

use App\Models\PropertyModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Item 3 do pedido de ajustes de 30/09/2026: a seção "Imóveis no mapa" da
 * home precisa oferecer a ferramenta de desenhar um perímetro. O controle é
 * montado em JavaScript (assets/js/map-draw-toolbar.js) e, ao concluir o
 * desenho, leva para a busca com o polígono na URL — este teste trava o que
 * o servidor precisa entregar para isso acontecer; o comportamento no
 * navegador fica com e2e/home-map-draw.spec.ts.
 */
final class HomeMapDrawToolbarTest extends HabitawebTestCase
{
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

    public function testHomeComMapaCarregaABarraDeDesenhoApontandoParaABusca(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;
        (new PropertyModel())->insert([
            'account_id'   => $accountId,
            'titulo'       => 'Imóvel com coordenadas',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'apartamento',
            'cidade'       => 'São Paulo',
            'bairro'       => 'Centro',
            'preco'        => 500000,
            'latitude'     => -23.55,
            'longitude'    => -46.63,
            'status'       => 'ACTIVE',
        ]);

        // Corpo bruto: `TestResponse::getBody()` cai no DOMParser, que reserializa
        // o HTML (acentos viram entidades, texto de <script> pode mudar).
        $html = (string) $this->get('/')->response()->getBody();

        $this->assertStringContainsString('id="homeMap"', $html, 'Com imóvel ACTIVE georreferenciado a seção do mapa renderiza.');
        $this->assertStringContainsString('assets/js/map-draw-toolbar.js', $html, 'A home carrega a barra de desenho compartilhada.');
        $this->assertStringContainsString('HabitawebMapDraw.attach', $html);
        $this->assertStringContainsString("position: 'topright'", $html, 'O controle fica no canto oposto ao do zoom, sem sobreposição.');
        $this->assertStringContainsString('imoveis/mapa', $html, 'Concluir o desenho leva para a busca com o polígono.');
        $this->assertStringContainsString('desenhe uma área', $html, 'O subtítulo da seção anuncia a ferramenta.');
    }
}
