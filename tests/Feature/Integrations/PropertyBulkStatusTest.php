<?php

namespace Tests\Feature\Integrations;

use App\Database\Seeds\PlanSeeder;
use App\Models\PropertyExternalRefModel;
use App\Models\PropertyModel;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Publicação em massa.
 *
 * Uma importação de integração entra inteira como rascunho — sem este
 * endpoint, publicar os 96 imóveis que a sincronização real da Giusti trouxe
 * é abrir 96 formulários, um por um.
 *
 * @internal
 */
final class PropertyBulkStatusTest extends HabitawebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /**
     * Corpo de POST com CSRF.
     *
     * Os ids vão como STRING de propósito: FeatureTestTrait::populateGlobals()
     * joga o array cru em $_POST com os tipos nativos do PHP, e o filtro
     * global invalidchars quebra com int ("mb_check_encoding(): Argument #1
     * must be of type array|string|null") — um falso positivo que não existe
     * em requisição HTTP de verdade, onde $_POST é sempre string. Mesma
     * armadilha documentada em Tests\Support\ApiTestTrait.
     */
    private function withCsrf(array $data = []): array
    {
        return array_merge([csrf_token() => csrf_hash()], $data);
    }

    private function imovel(array $tenant, string $titulo, string $status = 'DRAFT', bool $espelhado = true): int
    {
        $id = model(PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => $titulo,
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'CASA',
            'preco'        => 300000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => $status,
        ], true);

        if ($espelhado) {
            model(PropertyExternalRefModel::class)->insert([
                'property_id'   => $id,
                'account_id'    => $tenant['account']->id,
                'provider_code' => 'simob',
                'external_id'   => 'ext-' . $id,
            ]);
        }

        return (int) $id;
    }

    public function testPublicaOsRascunhosImportadosDaConta(): void
    {
        $tenant = (new TenantFactory())->create();
        $a      = $this->imovel($tenant, 'Importado 1');
        $b      = $this->imovel($tenant, 'Importado 2');
        // Rascunho que NÃO veio de integração: fora do escopo.
        $proprio = $this->imovel($tenant, 'Rascunho do próprio tenant', 'DRAFT', espelhado: false);

        $this->actingAs($tenant['user'])->post('admin/properties/bulk-status', $this->withCsrf([
            'scope'  => 'imported_drafts',
            'status' => 'ACTIVE',
        ]));

        $model = model(PropertyModel::class);
        $this->assertSame('ACTIVE', $model->find($a)->status);
        $this->assertSame('ACTIVE', $model->find($b)->status);
        $this->assertSame('DRAFT', $model->find($proprio)->status, 'só os importados entram no escopo');
    }

    public function testPublicaSomenteOsIdsSelecionados(): void
    {
        $tenant = (new TenantFactory())->create();
        $a      = $this->imovel($tenant, 'Selecionado');
        $b      = $this->imovel($tenant, 'Não selecionado');

        $this->actingAs($tenant['user'])->post('admin/properties/bulk-status', $this->withCsrf([
            'ids'    => [(string) $a],
            'status' => 'ACTIVE',
        ]));

        $model = model(PropertyModel::class);
        $this->assertSame('ACTIVE', $model->find($a)->status);
        $this->assertSame('DRAFT', $model->find($b)->status);
    }

    /**
     * Não existe filtro de tenant no framework (ver CLAUDE.md): sem a checagem
     * no controller, um POST com o id de outra conta publicaria imóvel alheio.
     */
    public function testNaoPublicaImovelDeOutraConta(): void
    {
        $tenant  = (new TenantFactory())->create();
        $vizinho = (new TenantFactory())->create();
        $alheio  = $this->imovel($vizinho, 'Imóvel do vizinho');

        $this->actingAs($tenant['user'])->post('admin/properties/bulk-status', $this->withCsrf([
            'ids'    => [(string) $alheio],
            'status' => 'ACTIVE',
        ]));

        $this->assertSame('DRAFT', model(PropertyModel::class)->find($alheio)->status);
    }

    public function testStatusForaDaListaERecusado(): void
    {
        $tenant = (new TenantFactory())->create();
        $id     = $this->imovel($tenant, 'Importado');

        $resposta = $this->actingAs($tenant['user'])->post('admin/properties/bulk-status', $this->withCsrf([
            'ids'    => [(string) $id],
            'status' => 'DELETED',
        ]));

        $body = json_decode((string) $resposta->getJSON(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('DRAFT', model(PropertyModel::class)->find($id)->status);
    }

    public function testPausarEmMassaTambemFunciona(): void
    {
        $tenant = (new TenantFactory())->create();
        $id     = $this->imovel($tenant, 'Publicado', 'ACTIVE');

        $this->actingAs($tenant['user'])->post('admin/properties/bulk-status', $this->withCsrf([
            'ids'    => [(string) $id],
            'status' => 'PAUSED',
        ]));

        $this->assertSame('PAUSED', model(PropertyModel::class)->find($id)->status);
    }
}
