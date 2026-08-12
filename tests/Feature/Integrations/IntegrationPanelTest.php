<?php

namespace Tests\Feature\Integrations;

use App\Database\Seeds\PlanSeeder;
use App\Models\AccountIntegrationConfigModel;
use App\Models\AccountIntegrationModel;
use App\Models\IntegrationMappingModel;
use App\Services\IntegrationService;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Painel de integrações batendo nas rotas de verdade, com usuário autenticado.
 *
 * O que importa provar aqui, além de as telas renderizarem: a credencial de um
 * tenant não pode ser lida nem sobrescrita por outro. Não existe filtro de
 * tenant no framework (ver CLAUDE.md) — o escopo é responsabilidade do
 * controller, então precisa de teste.
 *
 * @internal
 */
final class IntegrationPanelTest extends HabitawebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /**
     * Corpo de POST com o token CSRF.
     *
     * O filtro csrf vale para todo admin/* (ver SecurityFeatureTest), e no
     * FeatureTestTrait a SecurityException propaga crua em vez de virar 403.
     */
    private function withCsrf(array $data = []): array
    {
        return array_merge([csrf_token() => csrf_hash()], $data);
    }

    // ------------------------------------------------------------- renderiza

    public function testListaOsConectoresDisponiveis(): void
    {
        $tenant = (new TenantFactory())->create();

        $this->actingAs($tenant['user'])
            ->get('admin/integracoes')
            ->assertOK();

        $this->assertStringContainsString('Simob', $this->actingAs($tenant['user'])->get('admin/integracoes')->getBody());
    }

    public function testTelaDeConfiguracaoRenderizaOsCamposDoSchema(): void
    {
        $tenant = (new TenantFactory())->create();

        $result = $this->actingAs($tenant['user'])->get('admin/integracoes/simob');

        $result->assertOK();

        // Asserção pelos name= dos campos, e não pelos rótulos: o layout emite
        // acento como entidade HTML (imobili&aacute;ria), e o que importa aqui
        // é que o formulário saiu do config_schema com os campos certos.
        $html = $result->getBody();
        $this->assertStringContainsString('name="config[base_url]"', $html);
        $this->assertStringContainsString('name="config[token]"', $html);
        // O campo opcional de JWT também vem do config_schema.
        $this->assertStringContainsString('name="config[jwt_key]"', $html);
        // O token é sensível: tem que sair como campo de senha.
        $this->assertMatchesRegularExpression('/type="password"[^>]*name="config\[token\]"/', $html);
    }

    /** Abrir a tela já cria a linha, para as ações seguintes terem onde gravar. */
    public function testAbrirAConfiguracaoCriaAIntegracaoDesligada(): void
    {
        $tenant = (new TenantFactory())->create();

        $this->actingAs($tenant['user'])->get('admin/integracoes/simob')->assertOK();

        $integration = model(AccountIntegrationModel::class)
            ->findForAccount((int) $tenant['account']->id, 'simob');

        $this->assertNotNull($integration);
        $this->assertFalse($integration->is_active);
        $this->assertSame(AccountIntegrationModel::STATUS_PENDING, $integration->status);
    }

    public function testConectorInexistenteVoltaParaAListagem(): void
    {
        $tenant = (new TenantFactory())->create();

        $this->actingAs($tenant['user'])
            ->get('admin/integracoes/nao-existe')
            ->assertRedirectTo('admin/integracoes');
    }

    public function testTelasDeMapeamentoEExecucoesRenderizam(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->actingAs($tenant['user'])->get('admin/integracoes/simob');

        $this->actingAs($tenant['user'])->get('admin/integracoes/simob/mapeamentos')->assertOK();
        $this->actingAs($tenant['user'])->get('admin/integracoes/simob/execucoes')->assertOK();
    }

    public function testPainelExigeLogin(): void
    {
        $this->get('admin/integracoes')->assertRedirect();
    }

    // ----------------------------------------------------------- credenciais

    public function testSalvaCredenciaisCifradasENaoAsDevolveEmClaro(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->actingAs($tenant['user'])->get('admin/integracoes/simob');

        $this->actingAs($tenant['user'])->post('admin/integracoes/simob', $this->withCsrf([
            'config' => [
                'base_url' => 'https://minha.simob.com.br',
                'token'    => 'SEGREDO-DO-TENANT-4321',
            ],
        ]))->assertRedirect();

        $integration = model(AccountIntegrationModel::class)
            ->findForAccount((int) $tenant['account']->id, 'simob');

        // Em claro em lugar nenhum do banco.
        $this->dontSeeInDatabase('account_integration_configs', [
            'account_integration_id' => $integration->id,
            'config_value'           => 'SEGREDO-DO-TENANT-4321',
        ]);

        // Mas o conector recebe o valor certo.
        $this->assertSame(
            'SEGREDO-DO-TENANT-4321',
            model(AccountIntegrationConfigModel::class)->getConfig((int) $integration->id)['token']
        );

        // E a tela nunca mostra o segredo — só os 4 últimos dígitos.
        $html = $this->actingAs($tenant['user'])->get('admin/integracoes/simob')->getBody();
        $this->assertStringNotContainsString('SEGREDO-DO-TENANT-4321', $html);
        $this->assertStringContainsString('4321', $html, 'a máscara ••••4321 tem que aparecer');
    }

    /**
     * Salvar credencial derruba o "conectado" anterior: a chave mudou, e o
     * estado de conexão antigo não vale mais para a nova.
     */
    public function testSalvarCredencialVoltaOStatusParaPendente(): void
    {
        $tenant  = (new TenantFactory())->create();
        $service = new IntegrationService();
        $int     = $service->findOrCreate((int) $tenant['account']->id, 'simob');

        model(AccountIntegrationModel::class)->markTested((int) $int->id, true, 'ok');

        $this->actingAs($tenant['user'])->post('admin/integracoes/simob', $this->withCsrf([
            'config' => ['base_url' => 'https://outra.simob.com.br', 'token' => 'NOVO'],
        ]));

        $this->assertSame(
            AccountIntegrationModel::STATUS_PENDING,
            model(AccountIntegrationModel::class)->find($int->id)->status
        );
    }

    // --------------------------------------------------------- isolamento

    /**
     * O ponto mais sensível do painel: as rotas recebem só o código do
     * conector, e a conta sai de auth(). Não existe id na URL para trocar.
     */
    public function testTenantNaoAlcancaACredencialDeOutro(): void
    {
        $service = new IntegrationService();

        $a = (new TenantFactory())->create();
        $b = (new TenantFactory())->create();

        $intA = $service->findOrCreate((int) $a['account']->id, 'simob');
        $service->saveCredentials($intA, [
            'base_url' => 'https://tenant-a.simob.com.br',
            'token'    => 'TOKEN-DO-A',
        ]);

        // B abre a MESMA URL. Deve ver a integração dele, vazia.
        $html = $this->actingAs($b['user'])->get('admin/integracoes/simob')->getBody();

        $this->assertStringNotContainsString('TOKEN-DO-A', $html);
        $this->assertStringNotContainsString('tenant-a.simob.com.br', $html);

        // E salvar como B não pode encostar na credencial de A.
        $this->actingAs($b['user'])->post('admin/integracoes/simob', $this->withCsrf([
            'config' => ['base_url' => 'https://tenant-b.simob.com.br', 'token' => 'TOKEN-DO-B'],
        ]));

        $this->assertSame('TOKEN-DO-A', $service->credentials($service->find((int) $a['account']->id, 'simob'))['token']);
        $this->assertSame('TOKEN-DO-B', $service->credentials($service->find((int) $b['account']->id, 'simob'))['token']);
    }

    // --------------------------------------------------------- mapeamentos

    /**
     * A tela de mapeamento escreve em COLUNA de properties. Sem lista branca,
     * um POST forjado marcaria is_verified — que está em
     * PropertyService::GUARDED_FIELDS justamente por não ser do cliente.
     */
    public function testMapeamentoNaoAceitaColunaForaDaListaBranca(): void
    {
        $tenant  = (new TenantFactory())->create();
        $service = new IntegrationService();
        $int     = $service->findOrCreate((int) $tenant['account']->id, 'simob');

        model(IntegrationMappingModel::class)->seedSuggestion((int) $int->id, 'characteristic', [
            'external_id'    => '41',
            'external_label' => 'DORMITÓRIO(S)',
            'target_field'   => 'quartos',
        ]);

        $this->actingAs($tenant['user'])->post('admin/integracoes/simob/mapeamentos', $this->withCsrf([
            'characteristic' => ['41' => ['target' => 'is_verified']],
        ]))->assertRedirect();

        $mapping = model(IntegrationMappingModel::class)->indexedBy((int) $int->id, 'characteristic')['41'];

        $this->assertSame('quartos', $mapping->target_field, 'a escolha inválida foi descartada');
    }

    public function testMapeamentoValidoEGravadoEConfirmado(): void
    {
        $tenant  = (new TenantFactory())->create();
        $service = new IntegrationService();
        $int     = $service->findOrCreate((int) $tenant['account']->id, 'simob');

        model(IntegrationMappingModel::class)->seedSuggestion((int) $int->id, 'category', [
            'external_id'    => '17',
            'external_label' => 'APARTAMENTO',
        ]);

        $this->actingAs($tenant['user'])->post('admin/integracoes/simob/mapeamentos', $this->withCsrf([
            'category' => ['17' => ['target' => 'APARTAMENTO']],
        ]));

        $mapping = model(IntegrationMappingModel::class)->indexedBy((int) $int->id, 'category')['17'];

        $this->assertSame('APARTAMENTO', $mapping->target_value);
        $this->assertTrue($mapping->is_confirmed);
    }

    // ------------------------------------------------------------- ativação

    public function testNaoAtivaSincronizacaoSemConexaoTestada(): void
    {
        $tenant = (new TenantFactory())->create();
        (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');

        // getJSON() e não getBody(): em desenvolvimento a debug toolbar
        // prefixa o corpo com HTML e o json_decode volta null.
        $body = json_decode(
            (string) $this->actingAs($tenant['user'])
                ->post('admin/integracoes/simob/toggle', $this->withCsrf())
                ->getJSON(),
            true
        );

        $this->assertFalse($body['success']);
        $this->assertStringContainsString('Teste a conexão', $body['message']);
    }

    public function testDesconectarApagaCredenciaisMasMantemAConta(): void
    {
        $tenant  = (new TenantFactory())->create();
        $service = new IntegrationService();
        $int     = $service->findOrCreate((int) $tenant['account']->id, 'simob');
        $service->saveCredentials($int, ['base_url' => 'https://x.simob.com.br', 'token' => 'ABC']);

        $this->actingAs($tenant['user'])->post('admin/integracoes/simob/desconectar', $this->withCsrf())->assertRedirect();

        $this->assertSame([], $service->credentials($service->find((int) $tenant['account']->id, 'simob')));
        $this->seeInDatabase('account_integrations', [
            'account_id'    => $tenant['account']->id,
            'provider_code' => 'simob',
            'is_active'     => false,
        ]);
    }
}
