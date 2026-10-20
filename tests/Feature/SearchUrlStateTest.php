<?php

namespace Tests\Feature;

use App\Models\PropertyModel;
use App\Services\PublicPropertyVisibilityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * A URL da busca é o estado dela: recarregar ou compartilhar o link tem de
 * reabrir a página com os mesmos critérios. O JS grava quartos/banheiros/
 * vagas/ordenação na querystring, mas o controller não os lia — a página
 * recarregada voltava sem eles.
 *
 * @internal
 */
final class SearchUrlStateTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();

        $accountId = (int) (new TenantFactory())->create()['account']->id;
        $model     = new PropertyModel();
        foreach ([['Cidade Url A', 'Centro'], ['Cidade Url A', 'Agostini'], ['Cidade Url B', 'Bairro De B']] as [$cidade, $bairro]) {
            $model->insert([
                'account_id'   => $accountId,
                'titulo'       => "Imóvel {$cidade} {$bairro}",
                'status'       => 'ACTIVE',
                'tipo_negocio' => 'VENDA',
                'tipo_imovel'  => 'casa',
                'cidade'       => $cidade,
                'bairro'       => $bairro,
                'preco'        => 400000,
            ]);
        }
        PublicPropertyVisibilityService::invalidateCaches();
    }

    private function html(string $uri): string
    {
        return $this->get($uri)->response()->getBody();
    }

    public function testRecarregarPreservaTodosOsCriterios(): void
    {
        $html = $this->html('imoveis?tipo_imovel=casa&quartos=3&banheiros=2&vagas=1&max_price=500000&include_no_price=1&sort=price_asc');

        $this->assertMatchesRegularExpression('/id="inputQuartos" value="3"/', $html);
        $this->assertMatchesRegularExpression('/id="inputBanheiros" value="2"/', $html);
        $this->assertMatchesRegularExpression('/id="inputVagas" value="1"/', $html);
        $this->assertMatchesRegularExpression('/id="inputMaxPrice" value="500000"/', $html);
        $this->assertMatchesRegularExpression('/id="inputIncludeNoPrice" value="1"/', $html);
        $this->assertMatchesRegularExpression('/id="inputSort" value="price_asc"/', $html);
        $this->assertMatchesRegularExpression('/<option value="3"\s+selected>3\+<\/option>/', $html, 'modal de quartos pré-selecionado');
    }
}
