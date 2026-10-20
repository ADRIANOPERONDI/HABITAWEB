<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * property_specs(): cadastro incompleto não gera campo solto na interface.
 *
 * @internal
 */
final class PropertySpecsTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('format');
    }

    private function prop(array $f): object
    {
        return (object) $f;
    }

    private function keys(array $specs): array
    {
        return array_column($specs, 'key');
    }

    public function testTerrenoSoMostraAreaMesmoComQuartosPreenchidos(): void
    {
        $specs = property_specs($this->prop(['tipo_imovel' => 'terreno', 'area_total' => 360, 'quartos' => 3, 'vagas' => 2]), 'detail');

        $this->assertSame(['area'], $this->keys($specs));
        $this->assertSame('360 m²', $specs[0]['value']);
    }

    public function testAreaZeradaOuAusenteNaoGeraUnidadeSolta(): void
    {
        foreach ([null, 0, '0', '0.00', ''] as $area) {
            $specs = property_specs($this->prop(['tipo_imovel' => 'casa', 'area_total' => $area, 'quartos' => 2]), 'card');
            $this->assertNotContains('area', $this->keys($specs), 'area=' . var_export($area, true));
        }
    }

    public function testNaoInformadoSomeMasZeroInformadoApareceNoDetalhe(): void
    {
        $casa = $this->prop(['tipo_imovel' => 'casa', 'quartos' => 3, 'banheiros' => null, 'vagas' => 0]);

        $this->assertSame(['quartos'], $this->keys(property_specs($casa, 'card')), 'card esconde nulo e zero');
        $this->assertSame(['quartos', 'vagas'], $this->keys(property_specs($casa, 'detail')), 'detalhe mostra o zero informado');
    }

    public function testComercialNaoMostraQuartos(): void
    {
        $sala = $this->prop(['tipo_imovel' => 'sala', 'quartos' => 2, 'banheiros' => 1, 'area_total' => 45.5]);
        $specs = property_specs($sala, 'detail');

        $this->assertSame(['area', 'banheiros'], $this->keys($specs));
        $this->assertSame('45,5 m²', $specs[0]['value']);
    }

    public function testSuitesSoNoDetalhe(): void
    {
        $casa = $this->prop(['tipo_imovel' => 'casa', 'suites' => 1]);

        $this->assertSame([], $this->keys(property_specs($casa, 'card')));
        $this->assertSame(['suites'], $this->keys(property_specs($casa, 'detail')));
    }

    public function testCadastroVazioNaoGeraNada(): void
    {
        $this->assertSame([], property_specs($this->prop(['tipo_imovel' => 'casa']), 'detail'));
    }
}
