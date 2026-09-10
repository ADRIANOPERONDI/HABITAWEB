<?php

namespace Tests\Feature;

use App\Models\PaymentTransactionModel;
use App\Models\PropertyModel;
use App\Services\PropertyService;
use App\Services\PublicPropertyVisibilityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Regressão: o dropdown de bairro da busca pública listava bairro de
 * QUALQUER cidade do sistema (SELECT DISTINCT bairro sem WHERE cidade),
 * levando o visitante a escolher uma combinação cidade+bairro que
 * genuinamente não existe ("Guaraciaba" + "AGOSTINI", que só existe em São
 * Miguel do Oeste) — a busca devolvia 0 resultados, parecendo quebrada.
 */
final class BairroCidadeScopeTest extends HabitawebTestCase
{
    private int $accountId;
    private PropertyService $service;
    private string $cidadeA;
    private string $cidadeB;

    protected function setUp(): void
    {
        parent::setUp();
        PublicPropertyVisibilityService::invalidateCaches();

        $this->accountId = (int) (new TenantFactory())->create()['account']->id;
        $this->service = new PropertyService();

        $suffix = bin2hex(random_bytes(4));
        $this->cidadeA = "Cidade Bairro A {$suffix}";
        $this->cidadeB = "Cidade Bairro B {$suffix}";
    }

    protected function tearDown(): void
    {
        PublicPropertyVisibilityService::invalidateCaches();
        parent::tearDown();
    }

    private function insertProperty(int $accountId, string $cidade, string $bairro): int
    {
        return (int) (new PropertyModel())->insert([
            'account_id'   => $accountId,
            'titulo'       => 'Imovel ' . bin2hex(random_bytes(4)),
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'apartamento',
            'cidade'       => $cidade,
            'bairro'       => $bairro,
            'preco'        => 400000,
            'status'       => 'ACTIVE',
        ], true);
    }

    public function testBairrosRetornadosSaoApenasDaCidadeSelecionada(): void
    {
        $this->insertProperty($this->accountId, $this->cidadeA, 'Centro');
        $this->insertProperty($this->accountId, $this->cidadeA, 'Interior');
        $this->insertProperty($this->accountId, $this->cidadeB, 'Agostini');

        $bairros = array_map(
            static fn ($r) => $r->bairro,
            $this->service->getBairrosByCidade($this->cidadeA)
        );

        $this->assertContains('Centro', $bairros);
        $this->assertContains('Interior', $bairros);
        $this->assertNotContains('Agostini', $bairros);
    }

    public function testBairrosPorCidadeRespeitaPublicPropertyVisibilityService(): void
    {
        $tenantBloqueado = (new TenantFactory())->create();
        $accountBloqueado = (int) $tenantBloqueado['account']->id;
        $this->insertProperty($accountBloqueado, $this->cidadeA, 'BairroBloqueado');

        (new PaymentTransactionModel())->insert([
            'account_id'             => $accountBloqueado,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => 'pay_' . uniqid(),
            'amount'                 => 100,
            'status'                 => 'OVERDUE',
            'due_date'               => date('Y-m-d', strtotime('-10 days')),
        ]);

        $bairros = array_map(
            static fn ($r) => $r->bairro,
            $this->service->getBairrosByCidade($this->cidadeA)
        );

        $this->assertNotContains('BairroBloqueado', $bairros);
    }

    public function testBairrosPorCidadeNormalizaAcentoESlugComoResolveLocationName(): void
    {
        $cidadeComAcento = "São Paulo Bairro Teste " . bin2hex(random_bytes(4));
        $this->insertProperty($this->accountId, $cidadeComAcento, 'Vila Nova');

        helper('url');
        $slug = mb_url_title(mb_strtolower($cidadeComAcento), '-');

        $bairros = array_map(
            static fn ($r) => $r->bairro,
            $this->service->getBairrosByCidade($slug)
        );

        $this->assertContains('Vila Nova', $bairros);
    }

    public function testGetBairrosByCidadeComCidadeVaziaDevolveListaVazia(): void
    {
        $this->assertSame([], $this->service->getBairrosByCidade(''));
        $this->assertSame([], $this->service->getBairrosByCidade('   '));
    }

    public function testEndpointApiImoveisBairrosRetornaJsonComCidadeValida(): void
    {
        $this->insertProperty($this->accountId, $this->cidadeA, 'Centro');
        $this->insertProperty($this->accountId, $this->cidadeB, 'Agostini');

        $response = $this->get('api/imoveis/bairros?cidade=' . urlencode($this->cidadeA));
        $response->assertOK();

        $body = json_decode($response->getJSON(), true);
        $this->assertContains('Centro', $body['bairros']);
        $this->assertNotContains('Agostini', $body['bairros']);
    }

    public function testEndpointApiImoveisBairrosRetornaListaVaziaSemCidade(): void
    {
        $response = $this->get('api/imoveis/bairros');
        $response->assertOK();

        $body = json_decode($response->getJSON(), true);
        $this->assertSame([], $body['bairros']);
    }

    public function testExecuteSearchRendaBairrosEscopadosQuandoCidadeNaUrl(): void
    {
        $this->insertProperty($this->accountId, $this->cidadeA, 'Centro');
        $this->insertProperty($this->accountId, $this->cidadeB, 'Agostini');

        helper('url');
        $slug = mb_url_title(mb_strtolower($this->cidadeA), '-');

        $response = $this->get('imoveis/venda/' . $slug);
        $response->assertOK();
        $response->assertSee('Centro');
        $response->assertDontSee('Agostini');
    }
}
