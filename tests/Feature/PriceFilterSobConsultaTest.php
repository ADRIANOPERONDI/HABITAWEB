<?php

namespace Tests\Feature;

use App\Models\PropertyModel;
use App\Services\PropertyService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Regra do filtro de preço: com mínimo ou máximo informado só entram imóveis
 * com preço conhecido (> 0) dentro da faixa; "Sob consulta" (preco = 0) só
 * entra com include_no_price. A regra vale igual para lista, pins do mapa e
 * contagem — todos passam por PropertyService::applySearchFilters().
 *
 * @internal
 */
final class PriceFilterSobConsultaTest extends HabitawebTestCase
{
    private PropertyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $accountId     = (int) (new TenantFactory())->create()['account']->id;
        $this->service = new PropertyService();
        cache()->clean();

        $model = new PropertyModel();
        $rows  = [
            ['titulo' => 'Casa 300',      'preco' => 300000],
            ['titulo' => 'Casa 500',      'preco' => 500000],
            ['titulo' => 'Casa 900',      'preco' => 900000],
            ['titulo' => 'Casa Consulta', 'preco' => 0],
        ];
        foreach ($rows as $row) {
            $model->insert(array_merge($row, [
                'account_id'   => $accountId,
                'status'       => 'ACTIVE',
                'tipo_negocio' => 'VENDA',
                'tipo_imovel'  => 'casa',
                'cidade'       => 'São Miguel do Oeste',
                'bairro'       => 'Centro',
                'latitude'     => -26.72,
                'longitude'    => -53.51,
            ]));
        }
    }

    /** @return string[] títulos ordenados, vindos da lista */
    private function titulosDaLista(array $filters): array
    {
        // Escopa na cidade do teste: o banco compartilhado pode ter resíduo de e2e.
        $filters['cidade'] = 'São Miguel do Oeste';
        $result = $this->service->searchMapList($filters, 50, 1);
        $titles = array_map(static fn ($p) => $p->titulo, iterator_to_array($result['properties']));
        sort($titles);

        return $titles;
    }

    public function testMaximoSemOptInNaoTrazSobConsulta(): void
    {
        $this->assertSame(['Casa 300', 'Casa 500'], $this->titulosDaLista(['max_price' => 500000]));
    }

    public function testMinimoSemOptInNaoTrazSobConsulta(): void
    {
        $this->assertSame(['Casa 500', 'Casa 900'], $this->titulosDaLista(['min_price' => 500000]));
    }

    public function testOptInIncluiSobConsultaSemTrazerForaDaFaixa(): void
    {
        $this->assertSame(
            ['Casa 300', 'Casa 500', 'Casa Consulta'],
            $this->titulosDaLista(['max_price' => 500000, 'include_no_price' => '1'])
        );
    }

    public function testSemFaixaMantemTodosInclusiveSobConsulta(): void
    {
        $this->assertCount(4, $this->titulosDaLista([]));
        $this->assertCount(4, $this->titulosDaLista(['include_no_price' => '1']));
    }

    public function testListaPinsEContagemConcordam(): void
    {
        foreach ([[], ['include_no_price' => '1']] as $extra) {
            $filters = array_merge(['max_price' => 500000, 'cidade' => 'São Miguel do Oeste'], $extra);
            $list    = $this->service->searchMapList($filters, 50, 1);
            $pins    = $this->service->searchMapPins($filters);

            $this->assertCount(count($pins), iterator_to_array($list['properties']));
            $this->assertSame(count($pins), (int) $list['total']);
        }
    }

    public function testFaixaInvalidaGeraMensagem(): void
    {
        $this->assertNotNull(PropertyService::priceRangeError(['min_price' => 600000, 'max_price' => 500000]));
        $this->assertNotNull(PropertyService::priceRangeError(['min_price' => -1]));
        $this->assertNotNull(PropertyService::priceRangeError(['max_price' => 'abc']));
        $this->assertNull(PropertyService::priceRangeError(['min_price' => 500000, 'max_price' => 500000]));
        $this->assertNull(PropertyService::priceRangeError(['min_price' => '', 'max_price' => '']));
        $this->assertNull(PropertyService::priceRangeError([]));
    }

    public function testFaixaInvalidaNaoFiltraNoService(): void
    {
        $this->assertCount(4, $this->titulosDaLista(['min_price' => 600000, 'max_price' => 500000]));
    }
}
