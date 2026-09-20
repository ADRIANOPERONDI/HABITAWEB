<?php

namespace Tests\Feature;

use App\Models\PropertyModel;
use App\Services\PropertyService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * "SÃO MIGUEL DO OESTE" (sync Simob) e "São Miguel do Oeste" (ViaCEP do
 * formulário admin) eram gravadas como cidades diferentes. Como DISTINCT é
 * sensível a caixa no Postgres, o filtro CIDADE da busca mostrava a mesma
 * cidade duas vezes — e, pior, escolher uma das opções escondia os imóveis
 * gravados na outra grafia.
 *
 * Duas defesas, testadas aqui: a forma canônica na gravação (para não nascer
 * mais divergência) e a comparação por LOWER() na busca (para os imóveis já
 * gravados fora do padrão continuarem aparecendo).
 */
final class CidadeDuplicadaTest extends HabitawebTestCase
{
    private int $accountId;
    private PropertyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountId = (new TenantFactory())->create()['account']->id;
        $this->service   = new PropertyService();
        cache()->delete('search_filter_options');
    }

    private function criar(array $overrides): int
    {
        return (int) (new PropertyModel())->insert(array_merge([
            'account_id'   => $this->accountId,
            'titulo'       => 'Imóvel ' . bin2hex(random_bytes(3)),
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'casa',
            'cidade'       => 'São Miguel do Oeste',
            'bairro'       => 'Salete',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
            'preco'        => 300000,
        ], $overrides), true);
    }

    /** Gravar em caixa alta pelo serviço tem de produzir a forma canônica. */
    public function testGravacaoNormalizaACidade(): void
    {
        $resultado = $this->service->trySaveProperty([
            'account_id'   => $this->accountId,
            'titulo'       => 'Casa nova',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'casa',
            'cidade'       => 'SÃO MIGUEL DO OESTE',
            'bairro'       => 'Salete',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
            'preco'        => 300000,
        ], null, true);

        $this->assertTrue($resultado['success'], json_encode($resultado));
        $this->assertSame('São Miguel do Oeste', $resultado['data']->cidade);
    }

    /**
     * O sintoma que o cliente viu: a mesma cidade em duas linhas do dropdown.
     */
    public function testDropdownNaoRepeteACidadeEmDuasGrafias(): void
    {
        $this->criar(['cidade' => 'SÃO MIGUEL DO OESTE']);
        $this->criar(['cidade' => 'São Miguel do Oeste']);

        $cidades = array_map(
            static fn ($linha) => $linha->cidade,
            $this->service->getSearchFilterOptions()['cidades'],
        );

        $doOeste = array_values(array_filter(
            $cidades,
            static fn ($c) => mb_strtolower($c) === 'são miguel do oeste',
        ));

        $this->assertCount(1, $doOeste, 'cidade apareceu mais de uma vez: ' . json_encode($cidades));
        $this->assertSame('São Miguel do Oeste', $doOeste[0], 'deve preferir a grafia legível');
    }

    /**
     * O prejuízo real: metade do catálogo sumia da busca. Com as duas grafias
     * no banco, filtrar por uma tem de devolver TODOS os imóveis da cidade.
     */
    public function testFiltroDeCidadeAlcancaAsDuasGrafias(): void
    {
        $caixaAlta = $this->criar(['cidade' => 'SÃO MIGUEL DO OESTE']);
        $canonica  = $this->criar(['cidade' => 'São Miguel do Oeste']);

        $ids = array_map(
            static fn ($p) => (int) $p->id,
            $this->service->searchMapList(['cidade' => 'São Miguel do Oeste'])['properties'],
        );

        $this->assertContains($caixaAlta, $ids, 'imóvel gravado em caixa alta sumiu do filtro');
        $this->assertContains($canonica, $ids);
    }

    /** O slug SEO sem acento continua resolvendo — e também alcança as duas. */
    public function testSlugSemAcentoAlcancaAsDuasGrafias(): void
    {
        $caixaAlta = $this->criar(['cidade' => 'SÃO MIGUEL DO OESTE']);
        $canonica  = $this->criar(['cidade' => 'São Miguel do Oeste']);

        $ids = array_map(
            static fn ($p) => (int) $p->id,
            $this->service->searchMapList(['cidade' => 'sao-miguel-do-oeste'])['properties'],
        );

        $this->assertContains($caixaAlta, $ids);
        $this->assertContains($canonica, $ids);
    }

    /** O filtro não pode passar a pegar cidade que não foi pedida. */
    public function testFiltroNaoVazaParaOutraCidade(): void
    {
        $this->criar(['cidade' => 'SÃO MIGUEL DO OESTE']);
        $outra = $this->criar(['cidade' => 'Chapecó']);

        $ids = array_map(
            static fn ($p) => (int) $p->id,
            $this->service->searchMapList(['cidade' => 'São Miguel do Oeste'])['properties'],
        );

        $this->assertNotContains($outra, $ids);
    }
}
