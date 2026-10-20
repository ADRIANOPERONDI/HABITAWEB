<?php

namespace Tests\Feature;

use App\Models\PropertyModel;
use App\Services\PublicPropertyVisibilityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Endpoint do mapa (`/api/imoveis/mapa`): o pin de imóvel sem preço diz
 * "Sob consulta" (nunca "R$ 0"), o aluguel de R$ 2.500 não vira "3 mil" e
 * uma faixa invertida é recusada com 422.
 *
 * @internal
 */
final class MapPinPriceTest extends HabitawebTestCase
{
    private const CIDADE = 'Cidade Pin Preco';

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();

        $accountId = (int) (new TenantFactory())->create()['account']->id;
        $model     = new PropertyModel();
        foreach ([['Sem Preco', 0], ['Aluguel 2500', 2500]] as $i => [$titulo, $preco]) {
            $model->insert([
                'account_id'   => $accountId,
                'titulo'       => $titulo,
                'status'       => 'ACTIVE',
                'tipo_negocio' => 'ALUGUEL',
                'tipo_imovel'  => 'casa',
                'cidade'       => self::CIDADE,
                'bairro'       => 'Centro',
                'preco'        => $preco,
                'latitude'     => -26.7 - $i / 100,
                'longitude'    => -53.5,
            ]);
        }
        PublicPropertyVisibilityService::invalidateCaches();
    }

    protected function tearDown(): void
    {
        cache()->clean();
        parent::tearDown();
    }

    public function testPinsUsamRotuloSemDistorcerOPreco(): void
    {
        $response = $this->get('api/imoveis/mapa', ['cidade' => self::CIDADE, 'map_only' => 1]);
        $json     = json_decode($response->getJSON(), true);
        $labels = array_column($json['map_data'] ?? [], 'price');
        sort($labels);

        $this->assertSame(['R$ 2.500', 'Sob consulta'], $labels);
    }

    public function testFaixaInvertidaRetorna422(): void
    {
        $response = $this->get('api/imoveis/mapa', ['min_price' => 900, 'max_price' => 100]);

        $this->assertSame(422, $response->response()->getStatusCode());
    }
}
