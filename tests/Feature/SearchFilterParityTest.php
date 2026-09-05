<?php

namespace Tests\Feature;

use App\Models\PropertyModel;
use App\Services\PropertyService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Prova que `listPublicProperties()` (usada pelo catálogo do parceiro) e
 * `searchMapList()` (busca pública do mapa/lista) enxergam O MESMO conjunto de
 * imóveis para o mesmo filtro — desde a extração de
 * `PropertyService::applySearchFilters()`, único ponto que monta o `WHERE` de
 * ambas.
 *
 * Isto é pré-requisito da Fase 2 (exposição por slot): se as duas lanes
 * (orgânica e patrocinada) montassem o `WHERE` por caminhos que pudessem
 * divergir, um slot patrocinado poderia mostrar imóvel fora do filtro que o
 * visitante pediu.
 */
final class SearchFilterParityTest extends HabitawebTestCase
{
    private int $accountId;
    private PropertyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountId = (int) (new TenantFactory())->create()['account']->id;
        $this->service    = new PropertyService();

        $this->seedProperties();
    }

    private function seedProperties(): void
    {
        $model = new PropertyModel();

        $fixtures = [
            ['titulo' => 'Apto Centro Barato', 'tipo_negocio' => 'VENDA', 'tipo_imovel' => 'apartamento', 'cidade' => 'São Paulo', 'bairro' => 'Centro', 'preco' => 300000, 'quartos' => 2],
            ['titulo' => 'Apto Centro Caro',   'tipo_negocio' => 'VENDA', 'tipo_imovel' => 'apartamento', 'cidade' => 'São Paulo', 'bairro' => 'Centro', 'preco' => 1500000, 'quartos' => 4],
            ['titulo' => 'Casa Jardins',        'tipo_negocio' => 'VENDA', 'tipo_imovel' => 'casa',        'cidade' => 'São Paulo', 'bairro' => 'Jardins', 'preco' => 900000, 'quartos' => 3],
            ['titulo' => 'Apto Aluguel Centro', 'tipo_negocio' => 'ALUGUEL', 'tipo_imovel' => 'apartamento', 'cidade' => 'São Paulo', 'bairro' => 'Centro', 'preco' => 3000, 'quartos' => 1],
            ['titulo' => 'Terreno Outro Bairro','tipo_negocio' => 'VENDA', 'tipo_imovel' => 'terreno',     'cidade' => 'São Paulo', 'bairro' => 'Vila Nova', 'preco' => 400000, 'quartos' => 0],
            ['titulo' => 'Apto Rio',            'tipo_negocio' => 'VENDA', 'tipo_imovel' => 'apartamento', 'cidade' => 'Rio de Janeiro', 'bairro' => 'Copacabana', 'preco' => 800000, 'quartos' => 3],
        ];

        foreach ($fixtures as $fixture) {
            $model->insert(array_merge($fixture, [
                'account_id' => $this->accountId,
                'status'     => 'ACTIVE',
            ]));
        }
    }

    private function idsFromPublicList(array $filters): array
    {
        $result = $this->service->listPublicProperties($filters, 50);
        $ids    = array_map(static fn ($p) => (int) $p->id, iterator_to_array($result['properties']));
        sort($ids);

        return $ids;
    }

    private function idsFromMapList(array $filters): array
    {
        $result = $this->service->searchMapList($filters, 50, 1);
        $ids    = array_map(static fn ($p) => (int) $p->id, iterator_to_array($result['properties']));
        sort($ids);

        return $ids;
    }

    /** @return array<string, array{0: array}> */
    public static function filtrosProvider(): array
    {
        return [
            'sem filtro'                    => [[]],
            'por cidade'                     => [['cidade' => 'São Paulo']],
            'por cidade e bairro'            => [['cidade' => 'São Paulo', 'bairro' => 'Centro']],
            'por tipo de negocio'            => [['tipo_negocio' => 'VENDA']],
            'por tipo de imovel'             => [['tipo_imovel' => 'apartamento']],
            'por faixa de preco'             => [['min_price' => 350000, 'max_price' => 1000000]],
            'preco maximo isolado'           => [['max_price' => 500000]],
            'quartos minimos'                => [['quartos' => 3]],
            'min_price vazio nao quebra'     => [['min_price' => '', 'cidade' => 'São Paulo']],
            'combinado — o caso do cliente'  => [['cidade' => 'São Paulo', 'tipo_imovel' => 'apartamento', 'max_price' => 500000]],
        ];
    }

    /** @dataProvider filtrosProvider */
    public function testMesmoFiltroDevolveOMesmoConjuntoNasDuasLanes(array $filters): void
    {
        $viaListPublic = $this->idsFromPublicList($filters);
        $viaMapList    = $this->idsFromMapList($filters);

        $this->assertSame(
            $viaListPublic,
            $viaMapList,
            'listPublicProperties() e searchMapList() precisam enxergar o mesmo conjunto para o mesmo filtro — ' .
            'filtro: ' . json_encode($filters)
        );
    }

    public function testFiltroCombinadoExcluiOImovelCaroDoBairro(): void
    {
        // É literalmente o caso que o cliente descreveu: apto até R$500 mil no
        // Centro não pode trazer a casa/apto de R$1,5mi.
        $filters = ['bairro' => 'Centro', 'tipo_imovel' => 'apartamento', 'max_price' => 500000];

        $viaListPublic = $this->idsFromPublicList($filters);
        $viaMapList    = $this->idsFromMapList($filters);

        $caro = $this->buscarIdPorTitulo('Apto Centro Caro');

        $this->assertNotContains($caro, $viaListPublic);
        $this->assertNotContains($caro, $viaMapList);
        $this->assertSame($viaListPublic, $viaMapList);
    }

    private function buscarIdPorTitulo(string $titulo): int
    {
        return (int) (new PropertyModel())->where('titulo', $titulo)->first()->id;
    }
}
