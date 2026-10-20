<?php

namespace Tests\Feature;

use App\Models\PropertyModel;
use App\Services\PublicPropertyVisibilityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cadastro incompleto da imobiliária não pode gerar campo solto na interface:
 * "m²" sem número, ícone de quarto/vaga em terreno, seção sem conteúdo.
 *
 * @internal
 */
final class IncompleteListingRenderTest extends HabitawebTestCase
{
    private int $accountId;
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        $tenant          = (new TenantFactory())->create();
        $this->accountId = (int) $tenant['account']->id;
        $this->slug      = (string) $tenant['account']->slug;
    }

    private function imovel(array $over): int
    {
        $id = (int) (new PropertyModel())->insert(array_merge([
            'account_id'   => $this->accountId,
            'titulo'       => 'Cadastro Incompleto',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'terreno',
            'cidade'       => 'Cidade Incompleta',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
            'preco'        => 100000,
            'descricao'    => '',
        ], $over), true);
        PublicPropertyVisibilityService::invalidateCaches();

        return $id;
    }

    public function testTerrenoSemDadosNaoGeraCamposSoltosNaPaginaDoImovel(): void
    {
        $id   = $this->imovel(['area_total' => null, 'quartos' => null, 'banheiros' => null, 'vagas' => null]);
        $html = $this->get("imovel/{$id}")->response()->getBody();

        $this->assertStringNotContainsString(' m²</h5>', $html);
        $this->assertStringNotContainsString('>Quartos<', $html);
        $this->assertStringNotContainsString('>Vagas<', $html);
        $this->assertStringNotContainsString('Comodidades &amp; Diferenciais', $html);
        $this->assertStringNotContainsString('Outras Características', $html);
        $this->assertStringNotContainsString('>Descrição<', $html);
    }

    public function testTerrenoComAreaMostraSoAreaMesmoComQuartosPreenchidos(): void
    {
        $id   = $this->imovel(['area_total' => 450, 'quartos' => 3, 'vagas' => 2]);
        $html = $this->get("imovel/{$id}")->response()->getBody();

        $this->assertStringContainsString('450 m²', $html);
        $this->assertStringNotContainsString('>Quartos<', $html);
        $this->assertStringNotContainsString('>Vagas<', $html);
    }

    public function testCasaMostraZeroInformadoDeVagasNoDetalheMasNaoNoCartao(): void
    {
        $id   = $this->imovel(['tipo_imovel' => 'casa', 'quartos' => 3, 'banheiros' => 2, 'vagas' => 0, 'area_total' => 120]);
        $html = $this->get("imovel/{$id}")->response()->getBody();

        $this->assertStringContainsString('>Vagas<', $html);
    }

    public function testPerfilDaImobiliariaNaoMostraUnidadeSemValor(): void
    {
        $this->imovel(['area_total' => null, 'quartos' => null, 'banheiros' => null, 'vagas' => null]);
        $html = $this->get('imobiliaria/' . $this->slug)->response()->getBody();

        $this->assertStringContainsString('Cadastro Incompleto', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*m²/u', $html);
        $this->assertStringNotContainsString('fa-bed', $html);
        $this->assertStringNotContainsString('fa-car', $html);
    }
}
