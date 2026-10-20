<?php

namespace Tests\Feature;

use App\Services\PropertyService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * "CENTRO" (Simob) e "Centro" (formulário admin) eram duas opções no filtro
 * de bairro, e escolher uma escondia os imóveis da outra. A gravação passa a
 * devolver o nome canônico por cidade; o filtro encontra tudo pela mesma opção.
 *
 * @internal
 */
final class BairroDuplicadoTest extends HabitawebTestCase
{
    private const CIDADE = 'São Miguel do Oeste';

    private int $accountId;
    private PropertyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountId = (int) (new TenantFactory())->create()['account']->id;
        $this->service   = new PropertyService();
        cache()->clean();
    }

    private function salvar(string $bairro, string $cidade = self::CIDADE): object
    {
        $r = $this->service->trySaveProperty([
            'account_id'   => $this->accountId,
            'titulo'       => 'Imóvel ' . bin2hex(random_bytes(3)),
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'casa',
            'cidade'       => $cidade,
            'bairro'       => $bairro,
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
            'preco'        => 300000,
        ], null, true);

        $this->assertTrue($r['success'], json_encode($r));

        return $r['data'];
    }

    /** @return string[] */
    private function opcoes(string $cidade = self::CIDADE): array
    {
        return array_map(static fn ($l) => $l->bairro, $this->service->getBairrosByCidade($cidade));
    }

    public function testVariantesGravamUmUnicoBairroNoDropdown(): void
    {
        foreach (['CENTRO', 'Centro', ' centro ', 'AGOSTINI', 'Agostini', 'SALETE', 'Salete', 'JARDIM PEPERI', 'Jardim Peperi'] as $b) {
            $this->salvar($b);
        }

        $opcoes = $this->opcoes();
        sort($opcoes);

        $this->assertSame(['Agostini', 'Centro', 'Jardim Peperi', 'Salete'], $opcoes);
    }

    public function testFiltroPorBairroEncontraTodasAsVariantes(): void
    {
        $ids = [];
        foreach (['CENTRO', 'Centro', 'centro'] as $b) {
            $ids[] = (int) $this->salvar($b)->id;
        }

        $result = $this->service->searchMapList(['cidade' => self::CIDADE, 'bairro' => 'Centro'], 50, 1);
        $found  = array_map(static fn ($p) => (int) $p->id, iterator_to_array($result['properties']));

        $this->assertEqualsCanonicalizing($ids, $found);
    }

    public function testMesmoBairroEmOutraCidadeNaoSeMistura(): void
    {
        $this->salvar('CENTRO');
        $this->salvar('Centro', 'Guaraciaba');

        $this->assertSame(['Centro'], $this->opcoes());
        $this->assertSame(['Centro'], $this->opcoes('Guaraciaba'));
    }

    public function testAtualizacaoParcialSemCidadeUsaACidadeDoImovel(): void
    {
        $imovel = $this->salvar('Centro');

        $r = $this->service->trySaveProperty(['bairro' => 'CENTRO'], (int) $imovel->id, true, true);

        $this->assertTrue($r['success'], json_encode($r));
        $this->assertSame('Centro', $r['data']->bairro);
    }

    public function testBackfillUnificaImoveisJaGravadosSemFundirNomesDistintos(): void
    {
        $model = new \App\Models\PropertyModel();
        $base  = ['account_id' => $this->accountId, 'tipo_negocio' => 'VENDA', 'tipo_imovel' => 'casa',
            'cidade' => 'Cidade Backfill', 'estado' => 'SC', 'status' => 'ACTIVE', 'preco' => 1];
        foreach (['CENTRO', 'Centro', 'SAO JORGE', 'São Jorge', 'Centro Histórico'] as $i => $b) {
            $model->insert($base + ['titulo' => "bf$i", 'bairro' => $b]);
        }

        $resolver = new \App\Services\NeighborhoodResolver();

        $seco = $resolver->backfill(true);
        $this->assertSame(2, count($seco), 'dry-run deve apontar só Centro e São Jorge');
        $this->assertSame(1, $model->where('bairro', 'CENTRO')->countAllResults(), 'dry-run não grava');

        $resolver->backfill(false);

        $bairros = array_unique(array_column($model->where('cidade', 'Cidade Backfill')->findAll(), 'bairro'));
        sort($bairros);
        $this->assertSame(['Centro', 'Centro Histórico', 'São Jorge'], $bairros);
    }
}
