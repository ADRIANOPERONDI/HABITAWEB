<?php

namespace Tests\Feature;

use App\Models\PropertyModel;
use App\Services\PropertyService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre a correção do filtro de cidade/bairro por slug de URL SEO: antes, o
 * LIKE sensível a caso/acento fazia /imoveis/venda/sao-paulo NUNCA encontrar
 * imóveis de "São Paulo" — o filtro estava silenciosamente quebrado. Agora
 * resolveLocationName mapeia slug/sem-acento para o nome exato do banco (e o
 * match exato usa índice em vez de seq scan com '%...%').
 */
final class LocationSearchTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A lista distinct de cidades é cacheada 1h — limpa para o teste
        // enxergar os imóveis recém-inseridos.
        cache()->delete('search_filter_options');
    }

    protected function tearDown(): void
    {
        cache()->delete('search_filter_options');
        parent::tearDown();
    }

    private function insertProperty(int $accountId, string $cidade, string $bairro): int
    {
        $model = new PropertyModel();
        $model->insert([
            'account_id'   => $accountId,
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'apartamento',
            'titulo'       => "Imóvel em {$cidade}",
            'cidade'       => $cidade,
            'bairro'       => $bairro,
            'preco'        => 400000,
            'status'       => 'ACTIVE',
        ]);

        return (int) $model->getInsertID();
    }

    public function testSlugResolvesToExactAccentedCityName(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->insertProperty($tenant['account']->id, 'São Paulo', 'Água Branca');

        $service = new PropertyService();

        // Slug de URL (sem acento, com traço) e variações de caixa.
        $this->assertSame('São Paulo', $service->resolveLocationName('sao paulo', 'cidade'));
        $this->assertSame('São Paulo', $service->resolveLocationName('SAO PAULO', 'cidade'));
        $this->assertSame('Água Branca', $service->resolveLocationName('agua branca', 'bairro'));

        // Desconhecida: null (o filtro cai no LOWER() = do índice funcional).
        $this->assertNull($service->resolveLocationName('cidade-inexistente', 'cidade'));
    }

    public function testListPropertiesFiltersBySlugDerivedCity(): void
    {
        $tenant = (new TenantFactory())->create();
        $idSp  = $this->insertProperty($tenant['account']->id, 'São Paulo', 'Centro');
        $idPoa = $this->insertProperty($tenant['account']->id, 'Porto Alegre', 'Moinhos');

        // Exatamente o que o SearchController produz para /imoveis/venda/sao-paulo
        // (unslugify: 'sao paulo') — antes desta correção, retornava ZERO.
        $result = (new PropertyService())->listProperties(['cidade' => 'sao paulo', 'status' => 'ACTIVE'], 50);

        $ids = array_map(static fn ($p) => (int) $p->id, $result['properties']);
        $this->assertContains($idSp, $ids);
        $this->assertNotContains($idPoa, $ids);
    }
}
