<?php

namespace Tests\Feature\Integrations;

use App\Database\Seeds\PlanSeeder;
use App\Libraries\Integrations\Dto\TestResult;
use App\Libraries\Integrations\Exceptions\AuthException;
use App\Models\AccountIntegrationModel;
use App\Models\IntegrationMappingModel;
use App\Models\IntegrationOutboxModel;
use App\Models\LeadModel;
use App\Models\PropertyExternalRefModel;
use App\Services\IntegrationOutboxService;
use App\Services\IntegrationService;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\Integrations\FakeConnector;
use Tests\Support\Integrations\FakeIntegrationService;
use Tests\Support\HabitawebTestCase;

/**
 * Via de volta: lead do portal → CRM da plataforma de origem.
 *
 * O que mais importa provar aqui é o que NÃO pode acontecer: o lead sumir. Ele
 * é o produto — se a origem estiver fora do ar, o envio espera; se der erro
 * definitivo, o registro fica marcado como falho e reenviável.
 *
 * @internal
 */
final class IntegrationOutboxTest extends HabitawebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        cache()->clean();
    }

    /**
     * Tenant com integração ativa e um imóvel espelhado, pronto para gerar lead.
     *
     * @return array{0: array, 1: object, 2: int}
     */
    private function cenario(bool $ativa = true, bool $comMapeamento = true): array
    {
        $tenant      = (new TenantFactory())->create();
        $service     = new IntegrationService();
        $integration = $service->findOrCreate((int) $tenant['account']->id, 'simob');

        $service->saveCredentials($integration, ['base_url' => 'https://203.0.113.10', 'token' => 'x']);
        model(AccountIntegrationModel::class)->update($integration->id, [
            'is_active' => $ativa,
            'status'    => AccountIntegrationModel::STATUS_CONNECTED,
        ]);

        if ($comMapeamento) {
            model(IntegrationMappingModel::class)->seedSuggestion((int) $integration->id, 'category', [
                'external_id'    => '17',
                'external_label' => 'APARTAMENTO',
                'target_value'   => 'APARTAMENTO',
            ]);
        }

        $propertyId = model(\App\Models\PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Apto espelhado',
            'tipo_negocio' => 'ALUGUEL',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 1500,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);

        model(PropertyExternalRefModel::class)->insert([
            'property_id'   => $propertyId,
            'account_id'    => $tenant['account']->id,
            'provider_code' => 'simob',
            'external_id'   => '3376',
            'external_code' => '3364',
        ]);

        return [$tenant, model(AccountIntegrationModel::class)->find($integration->id), (int) $propertyId];
    }

    private function criarLead(array $tenant, int $propertyId): object
    {
        $id = model(LeadModel::class)->insert([
            'property_id'           => $propertyId,
            'account_id_anunciante' => $tenant['account']->id,
            'nome_visitante'        => 'Maria Souza',
            'email_visitante'       => 'maria@exemplo.com',
            'telefone_visitante'    => '(49) 99999-1234',
            'mensagem'              => 'Quero visitar.',
            'origem'                => 'SITE',
            // tipo_lead é NOT NULL sem default no schema; o LeadService
            // preenche 'MSG' por padrão, e aqui o insert é direto.
            'tipo_lead'             => 'MSG',
            'status'                => LeadModel::STATUS_NOVO,
        ], true);

        return model(LeadModel::class)->find($id);
    }

    // ----------------------------------------------------------- enfileirar

    public function testLeadDeImovelEspelhadoEntraNaFilaComOPayloadCerto(): void
    {
        [$tenant, $integration, $propertyId] = $this->cenario();
        $lead = $this->criarLead($tenant, $propertyId);

        $outboxId = (new IntegrationOutboxService())->enqueueLead($lead);

        $this->assertNotNull($outboxId);

        $item    = model(IntegrationOutboxModel::class)->find($outboxId);
        $payload = $item->payloadArray();

        $this->assertSame(IntegrationOutboxModel::STATUS_PENDING, $item->status);
        $this->assertSame('Maria Souza', $payload['lead']['nome']);
        $this->assertSame('3376', $payload['property']['external_id']);
        $this->assertSame('ALUGUEL', $payload['property']['tipo_negocio']);
        // Categoria é obrigatória no /crm_interesse/create: vem do de/para
        // invertido (tipo_imovel daqui -> id da origem).
        $this->assertSame('17', $payload['property']['external_categoria_id']);
    }

    /** Lead de imóvel cadastrado à mão não tem para onde ir — e isso não é erro. */
    public function testLeadDeImovelSemIntegracaoNaoEnfileiraNada(): void
    {
        $tenant = (new TenantFactory())->create();

        $propertyId = model(\App\Models\PropertyModel::class)->insert([
            'account_id'   => $tenant['account']->id,
            'titulo'       => 'Casa manual',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'CASA',
            'preco'        => 300000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'status'       => 'ACTIVE',
        ], true);

        $lead = $this->criarLead($tenant, (int) $propertyId);

        $this->assertNull((new IntegrationOutboxService())->enqueueLead($lead));
        $this->assertSame(0, model(IntegrationOutboxModel::class)->countAllResults());
    }

    public function testIntegracaoDesativadaNaoEnfileira(): void
    {
        [$tenant, $integration, $propertyId] = $this->cenario(ativa: false);
        $lead = $this->criarLead($tenant, $propertyId);

        $this->assertNull((new IntegrationOutboxService())->enqueueLead($lead));
    }

    /** Duplo clique no formulário do portal não pode virar dois interesses. */
    public function testNaoEnfileiraODuplicadoDoMesmoLead(): void
    {
        [$tenant, $integration, $propertyId] = $this->cenario();
        $lead    = $this->criarLead($tenant, $propertyId);
        $service = new IntegrationOutboxService();

        $primeiro = $service->enqueueLead($lead);
        $segundo  = $service->enqueueLead($lead);

        $this->assertSame($primeiro, $segundo);
        $this->assertSame(1, model(IntegrationOutboxModel::class)->countAllResults());
    }

    /**
     * O gatilho vive no caminho de criação do lead. Se a infraestrutura da
     * integração cair, o lead (que é o produto) não pode ir junto — enqueueLead
     * engole o erro e devolve null.
     */
    public function testFalhaDeInfraestruturaAoEnfileirarNaoPropaga(): void
    {
        [$tenant, $integration, $propertyId] = $this->cenario();
        $lead = $this->criarLead($tenant, $propertyId);

        // Model de refs que estoura na consulta, simulando banco indisponível.
        $refQuebrado = new class () extends PropertyExternalRefModel {
            public function findByProperty(int $propertyId): ?\App\Entities\PropertyExternalRef
            {
                throw new \RuntimeException('conexão com o banco perdida');
            }
        };

        $service = new IntegrationOutboxService(null, $refQuebrado);

        $this->assertNull($service->enqueueLead($lead), 'a exceção tem que morrer aqui dentro');
        $this->assertSame(0, model(IntegrationOutboxModel::class)->countAllResults());
    }

    /** O lead entra na fila pelo fluxo real do LeadService, não só na chamada direta. */
    public function testLeadServiceEnfileiraSozinho(): void
    {
        [$tenant, $integration, $propertyId] = $this->cenario();

        $result = service('leadService')->trySaveLead([
            'property_id'        => $propertyId,
            'nome_visitante'     => 'João Pereira',
            'email_visitante'    => 'joao@exemplo.com',
            'telefone_visitante' => '49999990000',
            'mensagem'           => 'Tenho interesse.',
            'origem'             => 'SITE',
        ]);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame(1, model(IntegrationOutboxModel::class)->countAllResults());
    }

    // -------------------------------------------------------------- entregar

    public function testEnvioBemSucedidoMarcaComoEnviado(): void
    {
        [$tenant, $integration, $propertyId] = $this->cenario();
        $lead     = $this->criarLead($tenant, $propertyId);
        $outboxId = (new IntegrationOutboxService())->enqueueLead($lead);

        $service = $this->serviceComConector(new FakeConnector([], null, TestResult::ok('enviado', ['response' => ['id' => 55]])));
        $stats   = $service->processDue();

        $this->assertSame(1, $stats['sent']);

        $item = model(IntegrationOutboxModel::class)->find($outboxId);
        $this->assertSame(IntegrationOutboxModel::STATUS_SENT, $item->status);
        $this->assertSame('55', $item->external_ref);
        $this->assertNotNull($item->sent_at);
    }

    /**
     * Servidor da imobiliária fora do ar: reagenda com backoff em vez de
     * desistir. O lead fica esperando.
     */
    public function testFalhaTemporariaReagendaComBackoff(): void
    {
        [$tenant, $integration, $propertyId] = $this->cenario();
        $lead     = $this->criarLead($tenant, $propertyId);
        $outboxId = (new IntegrationOutboxService())->enqueueLead($lead);

        $service = $this->serviceComConector(new FakeConnector([], new \RuntimeException('502 Bad Gateway')));
        $stats   = $service->processDue();

        $this->assertSame(1, $stats['retried']);

        $item = model(IntegrationOutboxModel::class)->find($outboxId);
        $this->assertSame(IntegrationOutboxModel::STATUS_PENDING, $item->status);
        $this->assertSame(1, $item->attempts);
        $this->assertStringContainsString('502', $item->last_error);
        $this->assertGreaterThan(time(), strtotime((string) $item->next_attempt_at), 'próxima tentativa no futuro');
    }

    public function testDesisteDepoisDeCincoTentativas(): void
    {
        [$tenant, $integration, $propertyId] = $this->cenario();
        $lead     = $this->criarLead($tenant, $propertyId);
        $outboxId = (new IntegrationOutboxService())->enqueueLead($lead);

        $service = $this->serviceComConector(new FakeConnector([], new \RuntimeException('fora do ar')));

        for ($i = 0; $i < IntegrationOutboxModel::MAX_ATTEMPTS; $i++) {
            // Desfaz o backoff para o item vencer de novo no laço.
            model(IntegrationOutboxModel::class)->update($outboxId, ['next_attempt_at' => date('Y-m-d H:i:s', time() - 10)]);
            $service->processDue();
        }

        $item = model(IntegrationOutboxModel::class)->find($outboxId);

        $this->assertSame(IntegrationOutboxModel::STATUS_FAILED, $item->status);
        $this->assertSame(IntegrationOutboxModel::MAX_ATTEMPTS, $item->attempts);
    }

    /** Credencial recusada não melhora com repetição: falha de uma vez. */
    public function testCredencialRecusadaFalhaSemGastarAsTentativas(): void
    {
        [$tenant, $integration, $propertyId] = $this->cenario();
        $lead     = $this->criarLead($tenant, $propertyId);
        $outboxId = (new IntegrationOutboxService())->enqueueLead($lead);

        $service = $this->serviceComConector(new FakeConnector([], new AuthException('Credencial recusada.')));
        $stats   = $service->processDue();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(
            IntegrationOutboxModel::STATUS_FAILED,
            model(IntegrationOutboxModel::class)->find($outboxId)->status
        );
    }

    /**
     * Tenant pausou a integração depois de o lead entrar na fila: o lead espera
     * a reativação em vez de queimar tentativa.
     */
    public function testIntegracaoPausadaAdiaSemGastarTentativa(): void
    {
        [$tenant, $integration, $propertyId] = $this->cenario();
        $lead     = $this->criarLead($tenant, $propertyId);
        $outboxId = (new IntegrationOutboxService())->enqueueLead($lead);

        model(AccountIntegrationModel::class)->update($integration->id, ['is_active' => false]);

        $stats = (new IntegrationOutboxService())->processDue();

        $this->assertSame(1, $stats['retried']);

        $item = model(IntegrationOutboxModel::class)->find($outboxId);
        $this->assertSame(0, $item->attempts, 'não pode ter gasto tentativa');
        $this->assertSame(IntegrationOutboxModel::STATUS_PENDING, $item->status);
    }

    public function testReenvioManualVoltaOItemParaAFila(): void
    {
        [$tenant, $integration, $propertyId] = $this->cenario();
        $lead     = $this->criarLead($tenant, $propertyId);
        $outboxId = (new IntegrationOutboxService())->enqueueLead($lead);

        model(IntegrationOutboxModel::class)->update($outboxId, [
            'status'   => IntegrationOutboxModel::STATUS_FAILED,
            'attempts' => 5,
        ]);

        (new IntegrationOutboxService())->retry((int) $outboxId);

        $item = model(IntegrationOutboxModel::class)->find($outboxId);
        $this->assertSame(IntegrationOutboxModel::STATUS_PENDING, $item->status);
        $this->assertSame(0, $item->attempts);
    }

    // ------------------------------------------------------------- reenvio UI

    public function testTenantNaoReenviaLeadDeOutro(): void
    {
        [$tenantA, $integrationA, $propertyA] = $this->cenario();
        $leadA = $this->criarLead($tenantA, $propertyA);
        (new IntegrationOutboxService())->enqueueLead($leadA);

        $tenantB = (new TenantFactory())->create();

        $this->actingAs($tenantB['user'])
            ->post('admin/leads/' . $leadA->id . '/reenviar-crm', [csrf_token() => csrf_hash()])
            ->assertStatus(403);
    }

    // ---------------------------------------------------------------- helper

    private function serviceComConector(FakeConnector $connector): IntegrationOutboxService
    {
        return new IntegrationOutboxService(
            null,
            null,
            null,
            null,
            new FakeIntegrationService($connector)
        );
    }
}
