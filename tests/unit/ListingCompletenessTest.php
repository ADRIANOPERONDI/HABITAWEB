<?php

namespace Tests\Unit;

use App\Libraries\Listing\ListingCompleteness;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ListingCompletenessTest extends CIUnitTestCase
{
    private function casa(array $over = []): object
    {
        return (object) array_merge([
            'tipo_imovel' => 'CASA', 'preco' => 700000, 'latitude' => -26.7, 'longitude' => -53.5,
            'descricao' => 'Casa ampla.', 'area_total' => 180, 'quartos' => 3, 'banheiros' => 2,
        ], $over);
    }

    public function testCasaCompletaComCincoFotos(): void
    {
        $r = ListingCompleteness::evaluate($this->casa(), 5);

        $this->assertTrue($r['complete']);
        $this->assertSame([], $r['missing']);
    }

    public function testCasaComQuatroFotosNaoEhCompleta(): void
    {
        $r = ListingCompleteness::evaluate($this->casa(), 4);

        $this->assertFalse($r['complete']);
        $this->assertSame(['pelo menos 5 fotos'], $r['missing']);
    }

    public function testImovelSobConsultaNaoGanhaOSelo(): void
    {
        $r = ListingCompleteness::evaluate($this->casa(['preco' => 0]), 10);

        $this->assertFalse($r['complete']);
        $this->assertContains('preço', $r['missing']);
    }

    public function testResidencialExigeAreaQuartosEBanheirosMasAceitaZeroInformado(): void
    {
        $r = ListingCompleteness::evaluate($this->casa(['area_total' => null, 'quartos' => null, 'banheiros' => null]), 5);
        $this->assertSame(['área', 'quartos', 'banheiros'], $r['missing']);

        $kitnet = ListingCompleteness::evaluate($this->casa(['tipo_imovel' => 'APARTAMENTO', 'quartos' => 0]), 5);
        $this->assertTrue($kitnet['complete'], 'zero quartos informado é cadastro completo');
    }

    public function testTerrenoPedeTresFotosEAreaENaoPedeQuartos(): void
    {
        $terreno = $this->casa(['tipo_imovel' => 'TERRENO', 'quartos' => null, 'banheiros' => null, 'area_total' => 360]);

        $this->assertTrue(ListingCompleteness::evaluate($terreno, 3)['complete']);
        $this->assertSame(['pelo menos 3 fotos'], ListingCompleteness::evaluate($terreno, 2)['missing']);
        $this->assertSame(['área'], ListingCompleteness::evaluate((object) array_merge((array) $terreno, ['area_total' => 0]), 3)['missing']);
    }

    public function testComercialUsaRegraDeTresFotos(): void
    {
        $sala = $this->casa(['tipo_imovel' => 'sala', 'quartos' => null, 'banheiros' => null]);

        $this->assertTrue(ListingCompleteness::evaluate($sala, 3)['complete']);
    }

    public function testSemLocalizacaoOuDescricaoNaoEhCompleto(): void
    {
        $r = ListingCompleteness::evaluate($this->casa(['latitude' => null, 'descricao' => '<p> </p>']), 5);

        $this->assertSame(['localização no mapa', 'descrição'], $r['missing']);
    }
}
