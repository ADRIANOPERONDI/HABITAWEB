<?php

namespace Tests\Feature;

use App\Libraries\Metrics\PriceBuckets;
use App\Libraries\Metrics\RedisMetricsBuffer;
use App\Models\SearchDailyModel;
use App\Services\SearchMetricsService;
use Tests\Support\HabitawebTestCase;

/**
 * Captura de busca (`search_daily`, Fase 4): bucketização de preço e as
 * três guardas de `SearchMetricsService::record` — sem filtro semântico,
 * bot, e dedup de pan/zoom por IP.
 *
 * Roda contra o Redis real do ambiente de teste (mesmo padrão de
 * PropertyViewMetricsTest/EmailQueueTest).
 */
final class SearchMetricsTest extends HabitawebTestCase
{
    private RedisMetricsBuffer $buffer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buffer = new RedisMetricsBuffer();

        if (! $this->buffer->markSeenOnce('hw:metrics:test:probe:' . uniqid(), 5)) {
            $this->markTestSkipped('Redis indisponível no ambiente de teste.');
        }

        $this->buffer->flushSearches(static fn () => true);
    }

    protected function tearDown(): void
    {
        $this->buffer->flushSearches(static fn () => true);
        parent::tearDown();
    }

    // ------------------------------------------------------- PriceBuckets

    public function testPrecoDeQuatrocentosEOitentaMilCaiNaFaixaDeTrezentosECinquentaAQuinhentos(): void
    {
        $this->assertSame('350-500k', PriceBuckets::bucketFor('VENDA', 480000));
    }

    public function testPrecoAcimaDeDoisMilhoesVaiParaFaixaAberta(): void
    {
        $this->assertSame('2M+', PriceBuckets::bucketFor('VENDA', 3000000));
    }

    public function testAluguelUsaEscadaPropria(): void
    {
        $this->assertSame('1000-2000', PriceBuckets::bucketFor('ALUGUEL', 1500));
        $this->assertSame('8000+', PriceBuckets::bucketFor('ALUGUEL', 9000));
    }

    public function testSemPrecoEQualquer(): void
    {
        $this->assertSame('QUALQUER', PriceBuckets::bucketFor('VENDA', null));
    }

    public function testBucketForSearchPrefereOTeto(): void
    {
        $this->assertSame('350-500k', PriceBuckets::bucketForSearch('VENDA', 100000, 480000));
        $this->assertSame('750k-1M', PriceBuckets::bucketForSearch('VENDA', 800000, null));
        $this->assertSame('QUALQUER', PriceBuckets::bucketForSearch('VENDA', null, null));
    }

    // -------------------------------------------------- guardas de record()

    private function captured(): array
    {
        $out = [];
        $this->buffer->flushSearches(function (string $dia, array $dims, int $buscas) use (&$out): bool {
            $out[] = [$dia, $dims, $buscas];

            return true;
        });

        return $out;
    }

    public function testBuscaSemFiltroSemanticoNaoConta(): void
    {
        (new SearchMetricsService())->record(['status' => 'ACTIVE'], '203.0.113.40', 'Mozilla/5.0');

        $this->assertSame([], $this->captured());
    }

    public function testBuscaComFiltroSemanticoConta(): void
    {
        (new SearchMetricsService())->record(
            ['cidade' => 'Chapecó', 'bairro' => 'Centro', 'tipo_negocio' => 'VENDA', 'max_price' => 480000],
            '203.0.113.41',
            'Mozilla/5.0'
        );

        $captured = $this->captured();
        $this->assertCount(1, $captured);
        [, $dims, $buscas] = $captured[0];
        $this->assertSame(['VENDA', 'Chapecó', 'Centro', '', '350-500k'], $dims);
        $this->assertSame(1, $buscas);
    }

    public function testBotNaoConta(): void
    {
        (new SearchMetricsService())->record(
            ['cidade' => 'Chapecó'],
            '203.0.113.42',
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
        );

        $this->assertSame([], $this->captured());
    }

    /** Pan/zoom do mesmo visitante (mesmo IP, mesmos filtros) não infla a contagem. */
    public function testPanDoMapaNaoInflaOBairroMaisProcurado(): void
    {
        $service = new SearchMetricsService();
        $filtros = ['cidade' => 'Chapecó', 'bairro' => 'Centro'];

        $service->record($filtros, '203.0.113.43', 'Mozilla/5.0');
        $service->record($filtros, '203.0.113.43', 'Mozilla/5.0'); // "pan" — mesmos filtros, mesmo IP
        $service->record($filtros, '203.0.113.43', 'Mozilla/5.0');

        $captured = $this->captured();
        $this->assertCount(1, $captured, 'buscas repetidas do mesmo IP em 60s devem virar uma só');
        $this->assertSame(1, $captured[0][2]);
    }

    /** Dois visitantes diferentes buscando a mesma coisa são duas buscas de verdade. */
    public function testDoisIpsDiferentesContamSeparado(): void
    {
        $service = new SearchMetricsService();
        $filtros = ['cidade' => 'Chapecó', 'bairro' => 'Centro'];

        $service->record($filtros, '203.0.113.50', 'Mozilla/5.0');
        $service->record($filtros, '203.0.113.51', 'Mozilla/5.0');

        $captured = $this->captured();
        $this->assertCount(1, $captured);
        $this->assertSame(2, $captured[0][2]);
    }

    public function testBairroMaisBuscadoAgregaNoBanco(): void
    {
        $dia = date('Y-m-01');
        $model = model(SearchDailyModel::class);
        $model->upsertCounter($dia, ['VENDA', 'Chapecó', 'Centro', '', 'QUALQUER'], 5);
        $model->upsertCounter($dia, ['VENDA', 'Chapecó', 'Efapi', '', 'QUALQUER'], 2);

        $top = $model->topBairros('Chapecó', $dia, $dia);

        $this->assertSame('Centro', $top[0]['bairro']);
        $this->assertSame(5, $top[0]['buscas']);
    }
}
