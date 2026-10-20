<?php

namespace Tests\Feature;

use App\Models\PropertyModel;
use App\Services\PublicPropertyVisibilityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Área pública: botões só de ícone têm nome acessível, o rodapé não mostra
 * rede social sem destino e contagens respeitam singular/plural.
 *
 * @internal
 */
final class PublicAccessibilityTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
    }

    private function html(string $uri): string
    {
        return $this->get($uri)->response()->getBody();
    }

    public function testRodapeSemRedesConfiguradasNaoMostraIconesSemDestino(): void
    {
        $html = $this->html('/');

        // Settings de teste vêm da migration com as redes vazias.
        $this->assertStringNotContainsString('footer-social', $html);
        $this->assertDoesNotMatchRegularExpression('/href="#?"[^>]*>\s*<i class="fa-brands fa-(facebook|instagram|whatsapp)/', $html);
    }

    public function testBotoesSoDeIconeTemNomeAcessivel(): void
    {
        $html = $this->html('/');

        $this->assertStringContainsString('aria-label="Abrir menu"', $html);
        $this->assertMatchesRegularExpression('/id="theme-toggle"[^>]*aria-label="/', $html);
        $this->assertMatchesRegularExpression('/class="btn-search-round" aria-label="Buscar imóveis"/', $html);
    }

    public function testBotaoFavoritarTemNomeEEstado(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;
        $id = (int) (new PropertyModel())->insert([
            'account_id' => $accountId, 'titulo' => 'Imóvel A11y', 'status' => 'ACTIVE', 'tipo_negocio' => 'VENDA',
            'tipo_imovel' => 'casa', 'cidade' => 'Cidade A11y', 'bairro' => 'Centro', 'preco' => 100000,
        ], true);
        PublicPropertyVisibilityService::invalidateCaches();

        $html = $this->html("imovel/{$id}");

        $this->assertMatchesRegularExpression('/class="[^"]*btn-favorite[^"]*"[^>]*aria-label="Favoritar" aria-pressed="false"/', $html);
        // Um único handler (no layout) e pela rota web: a API exige Bearer.
        $this->assertDoesNotMatchRegularExpression("#(url:|fetch\\()\\s*'[^']*api/v1/favorites/toggle#", $html);
        $this->assertSame(1, preg_match_all("#fetch\\('[^']*/favoritos/toggle'#", $html));
    }

    public function testListaDeParceirosUsaSingularComUmImovel(): void
    {
        $tenant = (new TenantFactory())->create();
        (new PropertyModel())->insert([
            'account_id' => (int) $tenant['account']->id, 'titulo' => 'Único', 'status' => 'ACTIVE', 'tipo_negocio' => 'VENDA',
            'tipo_imovel' => 'casa', 'cidade' => 'Cidade Plural', 'bairro' => 'Centro', 'preco' => 100000,
        ]);
        PublicPropertyVisibilityService::invalidateCaches();

        $html = $this->html('parceiros');

        $this->assertMatchesRegularExpression('/<strong>1<\/strong> Imóvel\b/u', $html);
        $this->assertDoesNotMatchRegularExpression('/<strong>1<\/strong> Imóveis/u', $html);
    }
}
