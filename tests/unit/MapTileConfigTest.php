<?php

namespace Tests\Unit;

use Config\Map;
use PHPUnit\Framework\TestCase;

/**
 * O portal apontava direto pro servidor de tiles do OpenStreetMap, que é
 * mantido por doação, não permite uso comercial e bloqueou o domínio: o mapa
 * virou um mosaico de quadros "Access blocked". A URL agora vem do .env.
 *
 * @internal
 */
final class MapTileConfigTest extends TestCase
{
    private array $originais = [];

    protected function tearDown(): void
    {
        foreach ($this->originais as $chave => $valor) {
            if ($valor === false) {
                unset($_ENV[$chave], $_SERVER[$chave]);
                putenv($chave);
            } else {
                $this->definir($chave, $valor);
            }
        }

        parent::tearDown();
    }

    private function definir(string $chave, string $valor): void
    {
        $this->originais[$chave] ??= getenv($chave);
        $_ENV[$chave]    = $valor;
        $_SERVER[$chave] = $valor;
        putenv("{$chave}={$valor}");
    }

    public function testUsaOProvedorConfigurado(): void
    {
        $this->definir('MAP_TILE_URL', 'https://api.exemplo.com/{z}/{x}/{y}.png?key=abc');
        $this->definir('MAP_TILE_ATTRIBUTION', '&copy; Exemplo');
        $this->definir('MAP_TILE_MAX_ZOOM', '20');

        $config = new Map();

        $this->assertSame('https://api.exemplo.com/{z}/{x}/{y}.png?key=abc', $config->tileUrl);
        $this->assertSame('&copy; Exemplo', $config->tileAttribution);
        $this->assertSame(20, $config->tileMaxZoom);
    }

    /**
     * A armadilha do env.example: a chave existe mas está vazia. env() devolve
     * string vazia, não o default — sem a guarda, o Leaflet recebia '' e o
     * mapa ficava branco.
     */
    public function testChaveVaziaCaiNoDefaultEmVezDeApagarOMapa(): void
    {
        $this->definir('MAP_TILE_URL', '');
        $this->definir('MAP_TILE_ATTRIBUTION', '   ');

        $config = new Map();

        $this->assertNotSame('', $config->tileUrl);
        $this->assertStringContainsString('{z}', $config->tileUrl);
        $this->assertNotSame('', trim($config->tileAttribution));
    }

    /**
     * Provedor de 512 px precisa dizer isso ao Leaflet. Sem `tileSize`, cada
     * tile ocupa metade do espaço e o mapa mostra o dobro da área no mesmo
     * zoom — não quebra, fica silenciosamente na escala errada.
     */
    public function testTamanhoDoTileEDeslocamentoDeZoomVemDoAmbiente(): void
    {
        $this->definir('MAP_TILE_SIZE', '512');
        $this->definir('MAP_TILE_ZOOM_OFFSET', '-1');

        $config = new Map();

        $this->assertSame(512, $config->tileSize);
        $this->assertSame(-1, $config->tileZoomOffset);
    }

    /** O default tem de ser o que o Leaflet já assume, pra não mexer em quem não configurou. */
    public function testDefaultEeOComportamentoPadraoDoLeaflet(): void
    {
        $config = new Map();

        $this->assertSame(256, $config->tileSize);
        $this->assertSame(0, $config->tileZoomOffset);
    }

    /**
     * Provedor com chave restrita por dominio precisa receber o Referer. Sem
     * politica explicita o navegador nao manda nenhum, o provedor responde 401
     * e o Chrome bloqueia como ORB — mapa em branco, zero erro no console.
     */
    public function testPoliticaDeReferrerTemDefaultQueMandaAOrigem(): void
    {
        $config = new Map();

        $this->assertNotSame('', $config->tileReferrerPolicy);
        $this->assertNotSame('no-referrer', $config->tileReferrerPolicy);
        $this->assertSame('strict-origin-when-cross-origin', $config->tileReferrerPolicy);
    }

    public function testPoliticaDeReferrerVemDoAmbiente(): void
    {
        $this->definir('MAP_TILE_REFERRER_POLICY', 'origin');

        $this->assertSame('origin', (new Map())->tileReferrerPolicy);
    }

    /** O template precisa servir pro Leaflet: sem {z}/{x}/{y} não existe tile. */
    public function testDefaultEUmTemplateValidoDeTile(): void
    {
        $config = new Map();

        foreach (['{z}', '{x}', '{y}'] as $marcador) {
            $this->assertStringContainsString($marcador, $config->tileUrl);
        }

        $this->assertGreaterThan(0, $config->tileMaxZoom);
    }
}
