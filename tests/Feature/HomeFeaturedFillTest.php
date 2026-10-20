<?php

namespace Tests\Feature;

use App\Models\PropertyModel;
use App\Services\PropertyService;
use App\Services\PublicPropertyVisibilityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Home: "Destaques Recomendados" completa com os imóveis mais recentes quando
 * não há destaques contratados suficientes (antes mostrava "Novos imóveis em
 * breve" num site cheio de imóveis), e a seção de parceiros passa a se
 * chamar "Parceiros em destaque".
 *
 * @internal
 */
final class HomeFeaturedFillTest extends HabitawebTestCase
{
    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        $this->accountId = (int) (new TenantFactory())->create([], 'DIAMANTE')['account']->id;
    }

    private function imovel(string $titulo, bool $destaque = false): int
    {
        return (int) (new PropertyModel())->insert([
            'account_id'   => $this->accountId,
            'titulo'       => $titulo,
            'status'       => 'ACTIVE',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'casa',
            'cidade'       => 'Cidade Vitrine',
            'bairro'       => 'Centro',
            'preco'        => 300000,
            'is_destaque'  => $destaque,
        ], true);
    }

    public function testCompletaComRecentesDepoisDosDestaquesSemRepetir(): void
    {
        $recentes  = [$this->imovel('Recente 1'), $this->imovel('Recente 2'), $this->imovel('Recente 3')];
        $destaque  = $this->imovel('Destaque Pago', true);
        PublicPropertyVisibilityService::invalidateCaches();

        $ids = array_map(static fn ($p) => (int) $p->id, (new PropertyService())->getHomeFeaturedProperties(8));

        $this->assertLessThanOrEqual(8, count($ids));
        $this->assertSame($ids, array_values(array_unique($ids)), 'nenhum imóvel aparece duas vezes');
        $this->assertContains($destaque, $ids);
        foreach ($recentes as $id) {
            $this->assertContains($id, $ids, 'imóvel recente completa a vitrine');
            $this->assertGreaterThan(array_search($destaque, $ids, true), array_search($id, $ids, true), 'destaque pago vem antes dos recentes');
        }
    }

    public function testHomeNaoMostraEmBreveQuandoHaImoveisEUsaTituloDeParceirosEmDestaque(): void
    {
        $this->imovel('Qualquer Recente');
        PublicPropertyVisibilityService::invalidateCaches();

        $html = $this->get('/')->response()->getBody();

        $this->assertStringNotContainsString('Novos imóveis em breve', $html);
        $this->assertStringContainsString('Qualquer Recente', $html);
        $this->assertStringNotContainsString('alta qualidade visual', $html);
    }
}
