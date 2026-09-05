<?php

namespace Tests\Feature;

use App\Libraries\Metrics\RedisMetricsBuffer;
use App\Libraries\Metrics\ViewOrigin;
use App\Models\PropertyModel;
use App\Models\PropertyViewDailyModel;
use App\Models\PropertyViewSourceDailyModel;
use App\Models\SearchDailyModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Série diária de visualização (Fase 4): `RedisMetricsBuffer` (fim a fim
 * contra Redis real) e o UPSERT que soma em vez de substituir.
 *
 * Roda contra o Redis real do ambiente de teste (cache.redis.* do
 * .env.testing, DB isolado do dev) — mesmo padrão de EmailQueueTest.
 */
final class PropertyViewMetricsTest extends HabitawebTestCase
{
    private RedisMetricsBuffer $buffer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buffer = new RedisMetricsBuffer();

        if (! $this->buffer->markSeenOnce('hw:metrics:test:probe:' . uniqid(), 5)) {
            $this->markTestSkipped('Redis indisponível no ambiente de teste.');
        }

        $this->drain();
    }

    protected function tearDown(): void
    {
        $this->drain();
        parent::tearDown();
    }

    private function drain(): void
    {
        $this->buffer->flushPropertyViews(static fn () => true);
        $this->buffer->flushPropertyViewSources(static fn () => true);
        $this->buffer->flushSearches(static fn () => true);
    }

    private function property(): int
    {
        $tenant = (new TenantFactory())->create();

        return (int) model(PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Imovel Metricas',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 400000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);
    }

    public function testTresHitsDoMesmoIpContamTresViewsMasUmaUnica(): void
    {
        $propertyId = $this->property();

        $this->buffer->bufferPropertyView($propertyId, 'DIRETO', '203.0.113.10', 'AgenteX');
        $this->buffer->bufferPropertyView($propertyId, 'DIRETO', '203.0.113.10', 'AgenteX');
        $this->buffer->bufferPropertyView($propertyId, 'DIRETO', '203.0.113.10', 'AgenteX');

        $captured = [];
        $this->buffer->flushPropertyViews(function (int $id, string $dia, int $views, int $unicas) use (&$captured): bool {
            $captured[] = [$id, $dia, $views, $unicas];

            return true;
        });

        $this->assertCount(1, $captured);
        [$id, , $views, $unicas] = $captured[0];
        $this->assertSame($propertyId, $id);
        $this->assertSame(3, $views);
        $this->assertSame(1, $unicas);
    }

    public function testVisitantesDiferentesContamUnicasDiferentes(): void
    {
        $propertyId = $this->property();

        $this->buffer->bufferPropertyView($propertyId, 'DIRETO', '198.51.100.1', 'AgenteA');
        $this->buffer->bufferPropertyView($propertyId, 'DIRETO', '198.51.100.2', 'AgenteB');

        $captured = null;
        $this->buffer->flushPropertyViews(function (int $id, string $dia, int $views, int $unicas) use (&$captured): bool {
            $captured = [$views, $unicas];

            return true;
        });

        $this->assertSame([2, 2], $captured);
    }

    public function testFalhaNoApplyDevolveAContagemAoRedis(): void
    {
        $propertyId = $this->property();
        $this->buffer->bufferPropertyView($propertyId, 'DIRETO', '203.0.113.20', 'Agente');

        $tentativas = 0;
        $this->buffer->flushPropertyViews(function () use (&$tentativas): bool {
            $tentativas++;

            return false; // simula falha no apply
        });

        $this->assertSame(1, $tentativas);

        // Se a contagem voltou pro Redis, um segundo flush encontra 1 view de novo.
        $segunda = null;
        $this->buffer->flushPropertyViews(function (int $id, string $dia, int $views, int $unicas) use (&$segunda): bool {
            $segunda = $views;

            return true;
        });

        $this->assertSame(1, $segunda);
    }

    public function testViewsPorOrigemSaoSeparadas(): void
    {
        $propertyId = $this->property();

        $this->buffer->bufferPropertyView($propertyId, 'DIRETO', '203.0.113.30', 'A');
        $this->buffer->bufferPropertyView($propertyId, 'BUSCA', '203.0.113.31', 'B');
        $this->buffer->bufferPropertyView($propertyId, 'BUSCA', '203.0.113.32', 'C');

        $porOrigem = [];
        $this->buffer->flushPropertyViewSources(function (int $id, string $dia, string $origem, int $views) use (&$porOrigem): bool {
            $porOrigem[$origem] = $views;

            return true;
        });

        $this->assertSame(1, $porOrigem['DIRETO']);
        $this->assertSame(2, $porOrigem['BUSCA']);
    }

    public function testUpsertSomaEmVezDeSubstituir(): void
    {
        $propertyId = $this->property();
        $dia        = date('Y-m-d');
        $model      = model(PropertyViewDailyModel::class);

        $model->upsertCounters($propertyId, $dia, 3, 1);
        $model->upsertCounters($propertyId, $dia, 2, 1);

        $totais = $model->totalsFor($propertyId, $dia, $dia);

        $this->assertSame(5, $totais['views']);
        $this->assertSame(2, $totais['views_unicas']);
    }

    public function testBuscaDoMesmoDiaSomaNoMesmoRegistro(): void
    {
        $dia   = date('Y-m-d');
        $dims  = ['VENDA', 'Chapecó', 'Centro', 'APARTAMENTO', '350-500k'];
        $model = model(SearchDailyModel::class);

        $model->upsertCounter($dia, $dims, 1);
        $model->upsertCounter($dia, $dims, 1);
        $model->upsertCounter($dia, $dims, 1);

        $bairros = $model->topBairros('Chapecó', $dia, $dia);

        $this->assertSame('Centro', $bairros[0]['bairro']);
        $this->assertSame(3, $bairros[0]['buscas']);
    }

    public function testViewOriginClassificaPorReferer(): void
    {
        $this->assertSame('DIRETO', ViewOrigin::classify(''));
        $this->assertSame('DIRETO', ViewOrigin::classify(null));
        $this->assertSame('BUSCA', ViewOrigin::classify('https://www.google.com/search?q=imovel'));
        $this->assertSame('REDES_SOCIAIS', ViewOrigin::classify('https://www.instagram.com/'));
        $this->assertSame('REDES_SOCIAIS', ViewOrigin::classify('android-app://com.whatsapp'));
        $this->assertSame('OUTRO', ViewOrigin::classify('https://algum-portal-parceiro.com.br/'));
    }
}
