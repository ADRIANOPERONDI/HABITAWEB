<?php

namespace Tests\Feature\Integrations;

use App\Database\Seeds\PlanSeeder;
use App\Models\AccountIntegrationConfigModel;
use App\Models\AccountIntegrationModel;
use App\Models\IntegrationMappingModel;
use App\Models\IntegrationSyncRunModel;
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
        // jwt_key saiu do config_schema: nunca foi lido por SimobClient/SimobProvider
        // e só confundia o tenant com um campo sem efeito nenhum.
        $this->assertStringNotContainsString('name="config[jwt_key]"', $html);
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

    /**
     * O formulário de configure.php reenvia config[base_url] com o valor
     * ATUAL a cada submit, junto com as preferências de sync — sem detectar
     * mudança de verdade, salvar só "Máximo de fotos" já derrubava o status
     * pra PENDING, desligando "Sincronizar agora" sem o tenant ter mexido
     * em credencial nenhuma.
     */
    public function testSalvarSemMudarNadaNaoDerrubaOStatus(): void
    {
        $tenant  = (new TenantFactory())->create();
        $service = new IntegrationService();
        $int     = $service->findOrCreate((int) $tenant['account']->id, 'simob');
        $service->saveCredentials($int, ['base_url' => 'https://x.simob.com.br', 'token' => 'ABC']);
        model(AccountIntegrationModel::class)->markTested((int) $int->id, true, 'ok');

        // Reenvia o MESMO base_url (como o form real faz) e token em branco
        // (como o campo de senha sempre chega).
        $this->actingAs($tenant['user'])->post('admin/integracoes/simob', $this->withCsrf([
            'config'   => ['base_url' => 'https://x.simob.com.br', 'token' => ''],
            'settings' => ['max_images' => '10'],
        ]));

        $this->assertSame(
            AccountIntegrationModel::STATUS_CONNECTED,
            model(AccountIntegrationModel::class)->find($int->id)->status
        );
    }

    public function testPausarSyncNaoMudaOStatusDeConexao(): void
    {
        $tenant  = (new TenantFactory())->create();
        $service = new IntegrationService();
        $int     = $service->findOrCreate((int) $tenant['account']->id, 'simob');
        model(AccountIntegrationModel::class)->markTested((int) $int->id, true, 'ok');
        model(AccountIntegrationModel::class)->update($int->id, ['is_active' => true]);

        $service->toggleActive(model(AccountIntegrationModel::class)->find($int->id), false);

        $reloaded = model(AccountIntegrationModel::class)->find($int->id);
        $this->assertFalse((bool) $reloaded->is_active);
        $this->assertSame(AccountIntegrationModel::STATUS_CONNECTED, $reloaded->status);
    }

    /**
     * Sem isso, o estado normal depois de configurar e testar é "conectado,
     * mas is_active=false" — nem o sync automático nem o envio de leads
     * fazem nada até o tenant achar um botão separado de ativar.
     */
    public function testPrimeiroTesteBemSucedidoLigaOSyncAutomatico(): void
    {
        $tenant = (new TenantFactory())->create();
        $int    = (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');
        $this->assertFalse((bool) $int->is_active);

        model(AccountIntegrationModel::class)->markTested((int) $int->id, true, 'ok');

        $this->assertTrue((bool) model(AccountIntegrationModel::class)->find($int->id)->is_active);
    }

    /** Reativar depois de uma pausa DELIBERADA continua exigindo o toggle do tenant. */
    public function testTestarDeNovoNaoReativaQuemPausouDeProposito(): void
    {
        $tenant = (new TenantFactory())->create();
        $int    = (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');

        $model = model(AccountIntegrationModel::class);
        $model->markTested((int) $int->id, true, 'primeiro teste');
        $model->update($int->id, ['is_active' => false]);

        $model->markTested((int) $int->id, true, 'segundo teste, depois de pausar');

        $this->assertFalse((bool) $model->find($int->id)->is_active);
    }

    public function testDesconectarLiberaOsImoveisParaEdicao(): void
    {
        $tenant  = (new TenantFactory())->create();
        $service = new IntegrationService();
        $int     = $service->findOrCreate((int) $tenant['account']->id, 'simob');
        $service->saveCredentials($int, ['base_url' => 'https://x.simob.com.br', 'token' => 'ABC']);

        $propertyId = model(\App\Models\PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Espelhado',
            'tipo_negocio' => 'ALUGUEL',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 1000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);

        model(\App\Models\PropertyExternalRefModel::class)->insert([
            'property_id'   => $propertyId,
            'account_id'    => $tenant['account']->id,
            'provider_code' => 'simob',
            'external_id'   => '999',
        ]);

        $this->assertTrue($service->isManagedProperty($propertyId));

        $this->actingAs($tenant['user'])->post('admin/integracoes/simob/desconectar', $this->withCsrf())->assertRedirect();

        $this->assertFalse($service->isManagedProperty($propertyId));
        // O imóvel em si não é tocado — só o vínculo some.
        $this->assertNotNull(model(\App\Models\PropertyModel::class)->find($propertyId));
    }

    public function testRedescobrirInformaEncontradasNovasEAtualizadas(): void
    {
        $tenant = (new TenantFactory())->create();
        $int    = (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');

        model(IntegrationMappingModel::class)->seedSuggestion((int) $int->id, IntegrationMappingModel::KIND_CATEGORY, [
            'external_id'    => '17',
            'external_label' => 'ANTIGO NOME',
            'target_value'   => 'APARTAMENTO',
        ]);

        $connector = new \Tests\Support\Integrations\FakeConnector();
        $connector->mappingsToDiscover = [
            'category'       => [['external_id' => '17', 'external_label' => 'APARTAMENTO', 'external_type' => null]],
            'characteristic' => [['external_id' => '41', 'external_label' => 'DORMITÓRIO(S)', 'external_type' => '3']],
        ];

        $resumo = (new IntegrationService())->seedMappings($int, $connector);

        $this->assertSame(['found' => 1, 'new' => 0, 'updated' => 1], $resumo['category']);
        $this->assertSame(['found' => 1, 'new' => 1, 'updated' => 0], $resumo['characteristic']);
    }

    /**
     * Imóvel importado entra como rascunho: sem ver quantos estão parados
     * nessa fila, o tenant não descobre que precisa publicá-los — nem que
     * existe um botão pra isso.
     */
    public function testTelaMostraQuantosImportadosEstaoEmRascunho(): void
    {
        $tenant = (new TenantFactory())->create();
        $int    = (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');

        $propertyId = model(\App\Models\PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Importado em rascunho',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'CASA',
            'preco'        => 300000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'DRAFT',
        ], true);

        model(\App\Models\PropertyExternalRefModel::class)->insert([
            'property_id'   => $propertyId,
            'account_id'    => $tenant['account']->id,
            'provider_code' => 'simob',
            'external_id'   => '900',
        ]);

        $resposta = $this->actingAs($tenant['user'])->get('admin/integracoes/simob');

        $resposta->assertOK();
        $resposta->assertSee('Publicar os 1 rascunhos');
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

    // -------------------------------------------------- sincronizar agora

    /**
     * `dueForSync()` (quem o cron usa pra achar o que processar) exige
     * `is_active=true` — sem essa mesma checagem aqui, "Sincronizar agora"
     * aceitava o pedido de uma integração pausada, gravava
     * `sync_priority_requested_at` e a tela ficava mostrando "Agendado,
     * aguardando o cron..." pra sempre, porque o cron nunca ia pegar aquele
     * pedido. Recusar na hora, com o motivo, é melhor que um agendamento
     * que nunca se resolve.
     */
    public function testSincronizarAgoraRecusaIntegracaoPausada(): void
    {
        $tenant = (new TenantFactory())->create();
        $int    = (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');
        model(AccountIntegrationModel::class)->update($int->id, [
            'status'    => AccountIntegrationModel::STATUS_CONNECTED,
            'is_active' => false,
        ]);

        $body = json_decode(
            (string) $this->actingAs($tenant['user'])
                ->post('admin/integracoes/simob/sincronizar', $this->withCsrf())
                ->getJSON(),
            true
        );

        $this->assertFalse($body['success']);
        $this->assertStringContainsString('Ative a sincronização automática', $body['message']);

        $reloaded = model(AccountIntegrationModel::class)->find($int->id);
        $this->assertNull($reloaded->sync_priority_requested_at, 'nao pode agendar um pedido que o cron nunca vai atender');
    }

    /**
     * "Sincronizar agora" rodava a sincronização de verdade dentro deste
     * mesmo request — download de imagens incluído — e estourava o
     * max_execution_time do PHP em catálogos do tamanho normal de uma
     * imobiliária (reproduzido contra a API real do Simob). Agora só marca
     * sync_priority_requested_at pro cron `integration:sync` (a cada 1 min)
     * consumir; o request responde na hora.
     */
    public function testSincronizarAgoraApenasAgendaEResponoDeImediato(): void
    {
        $tenant = (new TenantFactory())->create();
        $int    = (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');
        // is_active=true: "Sincronizar agora" recusa integracao pausada
        // (ver IntegrationsController::syncNow()) — sem isso o pedido ficaria
        // "agendado" pra sempre, ja que dueForSync() tambem exige is_active.
        model(AccountIntegrationModel::class)->update($int->id, ['status' => AccountIntegrationModel::STATUS_CONNECTED, 'is_active' => true]);

        $body = json_decode(
            (string) $this->actingAs($tenant['user'])
                ->post('admin/integracoes/simob/sincronizar', $this->withCsrf())
                ->getJSON(),
            true
        );

        $this->assertTrue($body['success']);
        $this->assertStringContainsString('agendada', $body['message']);

        $reloaded = model(AccountIntegrationModel::class)->find($int->id);
        $this->assertNotNull($reloaded->sync_priority_requested_at);
        // Nada rodou de fato: nenhum imóvel foi criado dentro deste request.
        $this->assertSame(0, model(\App\Models\PropertyModel::class)->where('account_id', $tenant['account']->id)->countAllResults());
    }

    public function testSincronizarAgoraRepetidoEmSeguidaERecusado(): void
    {
        $tenant = (new TenantFactory())->create();
        $int    = (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');
        // is_active=true: "Sincronizar agora" recusa integracao pausada
        // (ver IntegrationsController::syncNow()) — sem isso o pedido ficaria
        // "agendado" pra sempre, ja que dueForSync() tambem exige is_active.
        model(AccountIntegrationModel::class)->update($int->id, ['status' => AccountIntegrationModel::STATUS_CONNECTED, 'is_active' => true]);

        $this->actingAs($tenant['user'])->post('admin/integracoes/simob/sincronizar', $this->withCsrf());

        $body = json_decode(
            (string) $this->actingAs($tenant['user'])
                ->post('admin/integracoes/simob/sincronizar', $this->withCsrf())
                ->getJSON(),
            true
        );

        $this->assertFalse($body['success']);
        $this->assertStringContainsString('agendada há pouco', $body['message']);
    }

    // ------------------------------------------------------- status / abortar

    /**
     * Depois do "agendado" do teste acima, o painel só descobre o resultado
     * consultando este endpoint — é ele que faz o polling do configure.php
     * funcionar sem location.reload().
     */
    public function testEndpointDeStatusDevolveUltimaRodadaECooldown(): void
    {
        $tenant = (new TenantFactory())->create();
        $int    = (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');
        // is_active=true: "Sincronizar agora" recusa integracao pausada
        // (ver IntegrationsController::syncNow()) — sem isso o pedido ficaria
        // "agendado" pra sempre, ja que dueForSync() tambem exige is_active.
        model(AccountIntegrationModel::class)->update($int->id, ['status' => AccountIntegrationModel::STATUS_CONNECTED, 'is_active' => true]);

        $runModel = model(IntegrationSyncRunModel::class);
        $runId    = $runModel->start((int) $int->id, IntegrationSyncRunModel::TRIGGER_CRON);
        $runModel->finish($runId, IntegrationSyncRunModel::STATUS_SUCCESS, [
            'created_count' => 5,
            'ignored_count' => 2,
        ]);

        // Dispara o cooldown de verdade, do mesmo jeito que o botão "Sincronizar agora" faria.
        $this->actingAs($tenant['user'])->post('admin/integracoes/simob/sincronizar', $this->withCsrf());

        $body = json_decode(
            (string) $this->actingAs($tenant['user'])->get('admin/integracoes/simob/status')->getJSON(),
            true
        );

        $this->assertTrue($body['success']);
        $this->assertSame(AccountIntegrationModel::STATUS_CONNECTED, $body['status']);
        $this->assertFalse($body['running']);
        $this->assertSame(5, $body['last_run']['created_count']);
        $this->assertSame(2, $body['last_run']['ignored_count']);
        $this->assertGreaterThan(0, $body['cooldown_remaining'], 'acabou de agendar — o cooldown de 300s ainda está correndo');
    }

    /**
     * closeStaleRunning() (início de run()) já fecha rodadas presas sozinho,
     * mas só na PRÓXIMA vez que o cron passar por ali — pode levar minutos.
     * "Abortar" existe pro tenant não ficar de mãos atadas até lá.
     */
    public function testAbortarRodadaPresaLiberaATrava(): void
    {
        $tenant           = (new TenantFactory())->create();
        $int              = (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');
        $integrationModel = model(AccountIntegrationModel::class);
        $integrationModel->update($int->id, ['status' => AccountIntegrationModel::STATUS_CONNECTED]);
        $integrationModel->acquireLock((int) $int->id, 900);

        $runModel = model(IntegrationSyncRunModel::class);
        $runId    = $runModel->start((int) $int->id, IntegrationSyncRunModel::TRIGGER_CRON);
        // Simula uma rodada travada há mais tempo do que o razoável (15 min).
        $runModel->update($runId, ['started_at' => date('Y-m-d H:i:s', time() - 1000)]);

        $body = json_decode(
            (string) $this->actingAs($tenant['user'])
                ->post("admin/integracoes/simob/execucoes/{$runId}/abortar", $this->withCsrf())
                ->getJSON(),
            true
        );

        $this->assertTrue($body['success']);

        $run = $runModel->find($runId);
        $this->assertSame(IntegrationSyncRunModel::STATUS_ERROR, $run->status);
        $this->assertNotNull($run->error_message);

        $reloaded = $integrationModel->find($int->id);
        $this->assertNull($reloaded->sync_locked_until, 'a trava precisa ser liberada junto com o abortar');
    }

    public function testAbortarAntesDoTempoRazoavelERecusadoESemMexerNaTrava(): void
    {
        $tenant           = (new TenantFactory())->create();
        $int              = (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');
        $integrationModel = model(AccountIntegrationModel::class);
        $integrationModel->update($int->id, ['status' => AccountIntegrationModel::STATUS_CONNECTED]);
        $integrationModel->acquireLock((int) $int->id, 900);

        $runModel = model(IntegrationSyncRunModel::class);
        $runId    = $runModel->start((int) $int->id, IntegrationSyncRunModel::TRIGGER_CRON);

        $body = json_decode(
            (string) $this->actingAs($tenant['user'])
                ->post("admin/integracoes/simob/execucoes/{$runId}/abortar", $this->withCsrf())
                ->getJSON(),
            true
        );

        $this->assertFalse($body['success']);

        $run = $runModel->find($runId);
        $this->assertSame(IntegrationSyncRunModel::STATUS_RUNNING, $run->status, 'ainda dentro do tempo esperado — não deveria mexer na rodada');

        $reloaded = $integrationModel->find($int->id);
        $this->assertNotNull($reloaded->sync_locked_until, 'trava de uma rodada legítima não pode ser liberada antes da hora');
    }

    public function testAbortarNaoAlcancaExecucaoDeOutraConta(): void
    {
        $tenant  = (new TenantFactory())->create();
        $vizinho = (new TenantFactory())->create();
        $intVizinho = (new IntegrationService())->findOrCreate((int) $vizinho['account']->id, 'simob');
        $runModel   = model(IntegrationSyncRunModel::class);
        $runId      = $runModel->start((int) $intVizinho->id, IntegrationSyncRunModel::TRIGGER_CRON);
        $runModel->update($runId, ['started_at' => date('Y-m-d H:i:s', time() - 1000)]);

        (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');

        $body = json_decode(
            (string) $this->actingAs($tenant['user'])
                ->post("admin/integracoes/simob/execucoes/{$runId}/abortar", $this->withCsrf())
                ->getJSON(),
            true
        );

        $this->assertFalse($body['success']);
        $this->assertSame(IntegrationSyncRunModel::STATUS_RUNNING, $runModel->find($runId)->status);
    }

    // ------------------------------------------------ onboarding e mapeamentos

    /**
     * O passo atual é o primeiro que ainda não foi cumprido — sem isto, um
     * tenant que só salvou credenciais e nunca testou não tinha como saber,
     * olhando a tela, o que falta pra sincronizar de verdade.
     */
    public function testTelaMostraOPassoAtualDoOnboarding(): void
    {
        $tenant = (new TenantFactory())->create();
        (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');

        $html = (string) $this->actingAs($tenant['user'])->get('admin/integracoes/simob')->getBody();
        $this->assertStringContainsString('data-onboarding-current-step="1"', $html, 'sem credencial, o passo é o 1');

        $this->actingAs($tenant['user'])->post('admin/integracoes/simob', $this->withCsrf([
            'config' => ['base_url' => 'https://x.simob.com.br', 'token' => 'ABC'],
        ]));

        $html = (string) $this->actingAs($tenant['user'])->get('admin/integracoes/simob')->getBody();
        $this->assertStringContainsString('data-onboarding-current-step="2"', $html, 'credencial salva, falta testar');
    }

    /**
     * Confirma só quem já tem destino pelo palpite automático — a linha sem
     * sugestão continua pendente pra revisão manual, mesmo com o clique.
     */
    public function testConfirmarTodasAsSugestoes(): void
    {
        $tenant       = (new TenantFactory())->create();
        $int          = (new IntegrationService())->findOrCreate((int) $tenant['account']->id, 'simob');
        $mappingModel = model(IntegrationMappingModel::class);

        $mappingModel->seedSuggestion((int) $int->id, IntegrationMappingModel::KIND_CATEGORY, [
            'external_id'    => '17',
            'external_label' => 'APARTAMENTO',
            'target_value'   => 'APARTAMENTO',
        ]);
        $mappingModel->seedSuggestion((int) $int->id, IntegrationMappingModel::KIND_CATEGORY, [
            'external_id'    => '99',
            'external_label' => 'CATEGORIA DESCONHECIDA',
        ]);

        $this->actingAs($tenant['user'])->post('admin/integracoes/simob/mapeamentos', $this->withCsrf([
            'confirm_all_suggestions' => '1',
        ]))->assertRedirect();

        $mapped = $mappingModel->indexedBy((int) $int->id, IntegrationMappingModel::KIND_CATEGORY);

        $this->assertTrue($mapped['17']->is_confirmed, 'linha com destino sugerido devia ser confirmada');
        $this->assertFalse($mapped['99']->is_confirmed, 'sem destino, continua pendente de revisão manual');
    }

    /**
     * Nenhuma finalidade marcada traria catálogo vazio: nada seria importado
     * e nenhum imóvel existente teria como ser reconfirmado no próximo sync.
     * saveSettings() agora recusa em vez de cair num padrão silencioso.
     */
    public function testNenhumaFinalidadeMarcadaEDevolvidaComoErro(): void
    {
        $tenant  = (new TenantFactory())->create();
        $service = new IntegrationService();
        $int     = $service->findOrCreate((int) $tenant['account']->id, 'simob');
        $antes   = model(AccountIntegrationModel::class)->find($int->id)->settings();

        $this->actingAs($tenant['user'])->post('admin/integracoes/simob', $this->withCsrf([
            'config'   => [],
            // settings[finalidades][] de propósito ausente do POST.
            'settings' => ['initial_status' => 'DRAFT', 'max_images' => '20'],
        ]))->assertRedirect();

        $depois = model(AccountIntegrationModel::class)->find($int->id)->settings();
        $this->assertSame($antes, $depois, 'sem finalidade marcada, as preferências não podem ser sobrescritas');
    }
}
