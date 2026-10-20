<?php

namespace Tests\Feature;

use Tests\Support\HabitawebTestCase;

/**
 * Contador de fotos do cartão: um único número, sempre o total do anúncio.
 * A prévia carrega no máximo 5 fotos; quando o anúncio tem mais, o último
 * slide avisa quantas faltam em vez de o cartão mostrar "5" e "16" juntos.
 *
 * Cenários pedidos na revisão: 1 foto, 5 fotos e mais de 5 fotos.
 *
 * @internal
 */
final class CardPhotoCounterTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['sys', 'format', 'url']);
    }

    private function card(int $preview, int $total): string
    {
        $property = (object) [
            'id'              => 900 + $preview + $total,
            'titulo'          => 'Casa Salete',
            'cover_image'     => 'uploads/foto-0.jpg',
            'carousel_images' => array_map(static fn ($i) => "uploads/foto-{$i}.jpg", range(0, $preview - 1)),
            'media_count'     => $total,
        ];

        return view('web/partials/_property_media_carousel', ['property' => $property]);
    }

    public function testUmaFotoNaoMostraContadorNemSetas(): void
    {
        $html = $this->card(1, 1);

        $this->assertStringNotContainsString('premium-carousel-count', $html);
        $this->assertStringNotContainsString('data-bs-slide="next"', $html);
        $this->assertStringNotContainsString('premium-carousel-more', $html);
    }

    public function testCincoFotosMostraTotalCincoSemSlideExtra(): void
    {
        $html = $this->card(5, 5);

        $this->assertStringContainsString('&nbsp;/&nbsp;5', $html);
        $this->assertStringNotContainsString('premium-carousel-more', $html);
        $this->assertSame(5, substr_count($html, 'class="carousel-item'));
    }

    public function testMaisDeCincoMostraTotalRealEAvisaAsQueFaltam(): void
    {
        $html = $this->card(5, 16);

        $this->assertStringContainsString('&nbsp;/&nbsp;16', $html);
        $this->assertStringNotContainsString('&nbsp;/&nbsp;5<', $html);
        $this->assertStringContainsString('+11 fotos', $html);
        $this->assertStringContainsString('ver todas as 16', $html);
        $this->assertSame(6, substr_count($html, 'class="carousel-item'));
    }

    public function testSetasNaoAbremOAnuncio(): void
    {
        $html = $this->card(5, 16);

        // Os botões ficam dentro do <a> do cartão: sem isto o clique na seta
        // navegaria para o anúncio.
        $this->assertSame(2, substr_count($html, 'onclick="event.preventDefault(); event.stopPropagation();"'));
    }

    public function testListaDoMapaNaoRepeteOContadorNasSpecs(): void
    {
        $partial = file_get_contents(APPPATH . 'Views/web/partials/_property_map_list.php');

        $this->assertStringNotContainsString('fa-camera', $partial);
    }
}
