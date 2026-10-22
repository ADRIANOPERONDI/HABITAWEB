<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\LeadChargeModel;
use App\Models\LeadChargeRuleModel;
use App\Models\LeadModel;
use App\Models\PropertyModel;
use App\Services\PublicPropertyVisibilityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Validação do fluxo de leads (item 12 da revisão da área pública), ponta a
 * ponta pela rota pública `POST /leads`, com dados de teste e sem gateway
 * (nenhuma cobrança real):
 *  1. o contato chega à imobiliária responsável pelo imóvel — e só a ela;
 *  2. dois envios seguidos não geram contato nem cobrança duplicados;
 *  3. (recarregar a página não reenvia: coberto no navegador, e2e/lead-flow.spec.ts);
 *  4. falha de entrega do aviso fica identificada, não aparece como entregue;
 *  5. a imobiliária consulta imóvel, data, origem e situação do contato;
 *  6. cada cobrança fica ligada ao contato que a gerou;
 *  7. contato repetido (30 dias) e inválido seguem a regra comercial.
 *
 * @internal
 */
final class LeadFlowValidationTest extends HabitawebTestCase
{
    private array $dono;
    private array $outra;
    private int $imovelId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        cache()->clean();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(1, 250);

        $this->dono  = (new TenantFactory())->create(['email' => 'imobiliaria-dona-' . bin2hex(random_bytes(3)) . '@teste.local']);
        $this->outra = (new TenantFactory())->create();

        model(LeadChargeRuleModel::class)->insert([
            'account_id' => $this->dono['account']->id, 'provider_code' => null, 'tipo_negocio' => null,
            'model' => LeadChargeRuleModel::MODEL_FIXED, 'value' => 80, 'is_active' => true,
        ]);

        $this->imovelId = (int) model(PropertyModel::class)->insert([
            'account_id' => $this->dono['account']->id, 'titulo' => 'Casa Validação Lead', 'tipo_negocio' => 'VENDA',
            'tipo_imovel' => 'CASA', 'preco' => 450000, 'cidade' => 'São Miguel do Oeste', 'bairro' => 'Centro',
            'estado' => 'SC', 'status' => 'ACTIVE',
        ], true);
        PublicPropertyVisibilityService::invalidateCaches();
    }

    private function enviar(array $campos = []): \CodeIgniter\Test\TestResponse
    {
        return $this->post('leads', array_merge([
            csrf_token()         => csrf_hash(),
            'property_id'        => $this->imovelId,
            'nome_visitante'     => 'Maria Teste',
            'email_visitante'    => 'maria.teste@exemplo.com.br',
            'telefone_visitante' => '(49) 99988-7766',
            'mensagem'           => 'Tenho interesse nesta casa.',
        ], $campos));
    }

    private function leadsDoImovel(): array
    {
        return model(LeadModel::class)->where('property_id', $this->imovelId)->findAll();
    }

    private function painel(array $tenant, string $uri): string
    {
        return html_entity_decode($this->actingAs($tenant['user'])->get($uri)->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** 1 + 5 + 6: chega à imobiliária certa, com imóvel, data, origem, situação e cobrança ligada. */
    public function testContatoChegaAImobiliariaResponsavelComTudoQueElaPrecisaVer(): void
    {
        $resposta = $this->enviar();
        $resposta->assertOK();
        $json = json_decode($resposta->getJSON(), true);
        $this->assertTrue($json['success']);

        $leads = $this->leadsDoImovel();
        $this->assertCount(1, $leads);
        $lead = $leads[0];
        $this->assertSame((int) $this->dono['account']->id, (int) $lead->account_id_anunciante, 'lead vai para a dona do imóvel');
        $this->assertSame((int) $json['lead_id'], (int) $lead->id);

        // Notificação no painel para os usuários da imobiliária dona.
        $this->assertSame(1, model(\App\Models\NotificationModel::class)->where('user_id', $this->dono['user']->id)->where('title', 'Novo Lead Recebido')->countAllResults());

        // A imobiliária vê o contato com imóvel, data, origem e situação.
        $html = $this->painel($this->dono, 'admin/leads');
        $this->assertStringContainsString('Maria Teste', $html);
        $this->assertStringContainsString('Casa Validação Lead', $html);
        $this->assertStringContainsString($lead->created_at->format('d/m'), $html);
        $this->assertStringContainsString('Formulário', $html);
        $this->assertMatchesRegularExpression('/<option value="NOVO"\s+selected>Novo<\/option>/', $html);

        // Outra imobiliária não vê.
        $this->assertStringNotContainsString('Maria Teste', $this->painel($this->outra, 'admin/leads'));

        // A cobrança fica ligada ao contato que a gerou.
        $cobranca = model(LeadChargeModel::class)->findByLead((int) $lead->id);
        $this->assertNotNull($cobranca);
        $this->assertSame((int) $this->imovelId, (int) $cobranca->property_id);
        $this->assertSame(80.0, (float) $cobranca->commission_value);
        $this->assertSame(LeadChargeModel::STATUS_PENDING, $cobranca->status, 'só aprovada depois do prazo de contestação');
        $this->assertStringContainsString('R$ 80,00', $html, 'a cobrança aparece na linha do lead');
    }

    /** 2: duplo clique / reenvio idêntico — um contato e uma cobrança. */
    public function testDoisEnviosSeguidosNaoDuplicamContatoNemCobranca(): void
    {
        $this->enviar()->assertOK();
        $this->enviar()->assertOK();

        $leads = $this->leadsDoImovel();
        $this->assertCount(1, $leads);
        $this->assertSame(1, model(LeadChargeModel::class)->where('lead_id', $leads[0]->id)->countAllResults());
        $this->assertSame(1, model(LeadChargeModel::class)->where('account_id', $this->dono['account']->id)->countAllResults());
    }

    private function avisoPorEmail(bool $entrega): void
    {
        $notifier = new class ($entrega) extends \App\Services\NotificationService {
            public function __construct(private bool $entrega) { parent::__construct(); }
            public function sendEmail(string $to, string $subject, string $message, bool $immediate = false): bool { return $this->entrega; }
            public function sendWhatsApp(string $number, string $message): bool { return false; }
        };
        service('leadService')->setNotificationService($notifier);
    }

    protected function tearDown(): void
    {
        service('leadService')->setNotificationService(new \App\Services\NotificationService());
        parent::tearDown();
    }

    /** 4: servidor de e-mail recusou o aviso — fica registrado e visível como NÃO entregue. */
    public function testFalhaNoAvisoPorEmailFicaIdentificada(): void
    {
        $this->avisoPorEmail(false);
        $this->enviar()->assertOK();
        $lead = $this->leadsDoImovel()[0];

        $evento = db_connect()->table('lead_events')->where('lead_id', $lead->id)->where('evento', 'notificacao.email')->get()->getRow();
        $this->assertNotNull($evento, 'o resultado do aviso por e-mail é registrado');
        $this->assertFalse(json_decode($evento->payload, true)['enviado'], 'sem sendmail o aviso NÃO conta como entregue');

        $html = $this->painel($this->dono, 'admin/leads');
        $this->assertStringContainsString('e-mail não entregue', $html);
        $this->assertStringNotContainsString('e-mail enviado', $html);
    }

    /** 4b: entregue → registrado e visível como enviado. */
    public function testAvisoEntregueApareceComoEnviado(): void
    {
        $this->avisoPorEmail(true);
        $this->enviar()->assertOK();

        $html = $this->painel($this->dono, 'admin/leads');
        $this->assertStringContainsString('e-mail enviado', $html);
        $this->assertStringNotContainsString('e-mail não entregue', $html);
    }

    /** 7a: mesma pessoa no mesmo imóvel em 30 dias = mesmo lead; depois, lead novo. */
    public function testContatoRepetidoSegueARegraDos30Dias(): void
    {
        $this->enviar(['mensagem' => 'Primeira vez'])->assertOK();
        $this->enviar(['email_visitante' => 'MARIA.TESTE@exemplo.com.br', 'mensagem' => 'Voltei'])->assertOK();
        $this->assertCount(1, $this->leadsDoImovel(), 'repetido em 30 dias');

        model(LeadModel::class)->builder()->where('property_id', $this->imovelId)->update(['created_at' => date('Y-m-d H:i:s', strtotime('-31 days'))]);
        $this->enviar(['mensagem' => 'Um mês depois'])->assertOK();

        $this->assertCount(2, $this->leadsDoImovel(), 'depois de 30 dias é lead novo');
        $this->assertSame(2, model(LeadChargeModel::class)->where('account_id', $this->dono['account']->id)->countAllResults());
    }

    /** 7b: inválido — dados que não passam na validação nem viram lead; suspeito vira lead sem cobrança. */
    public function testContatoInvalidoNaoGeraCobranca(): void
    {
        $this->enviar(['email_visitante' => 'nao-e-email', 'telefone_visitante' => '12'])->assertStatus(422);
        $this->assertCount(0, $this->leadsDoImovel(), 'formulário inválido não registra nada');

        $this->enviar(['email_visitante' => 'fulano@mailinator.com'])->assertOK();
        $lead = $this->leadsDoImovel()[0];
        $cobranca = model(LeadChargeModel::class)->findByLead((int) $lead->id);

        $this->assertSame(LeadChargeModel::STATUS_WAIVED, $cobranca->status, 'e-mail descartável: lead registrado, cobrança dispensada');
        $this->assertEquals(0, (float) $cobranca->commission_value);
    }

    /** 7c: contestação dentro de 15 dias e decisão do administrador. */
    public function testContestacaoDentroDoPrazo(): void
    {
        $this->enviar()->assertOK();
        $cobranca = model(LeadChargeModel::class)->findByLead((int) $this->leadsDoImovel()[0]->id);

        $this->assertSame(date('Y-m-d', strtotime('+15 days')), substr((string) $cobranca->contest_deadline, 0, 10));

        $service = new \App\Services\LeadChargeService();
        $this->assertTrue($service->contest((int) $cobranca->id, (int) $this->dono['account']->id, 'Contato com telefone inexistente'));
        $this->assertFalse($service->contest((int) $cobranca->id, (int) $this->outra['account']->id, 'tentativa de outra conta'), 'outra conta não contesta');

        $service->resolveDispute((int) $cobranca->id, true, 'Telefone confirmado inexistente');
        $this->assertSame(LeadChargeModel::STATUS_WAIVED, model(LeadChargeModel::class)->find($cobranca->id)->status);
    }

    /** O e-mail para a imobiliária identifica o imóvel e escapa o que o visitante digitou. */
    public function testEmailDaImobiliariaIdentificaOImovelEEscapaODigitado(): void
    {
        $capturado = [];
        $notifier = new class ($capturado) extends \App\Services\NotificationService {
            public function __construct(private array &$capturado) { parent::__construct(); }
            public function sendEmail(string $to, string $subject, string $message, bool $immediate = false): bool
            {
                $this->capturado = compact('to', 'subject', 'message');

                return true;
            }
            public function sendWhatsApp(string $number, string $message): bool { return false; }
        };
        service('leadService')->setNotificationService($notifier);

        $this->enviar(['nome_visitante' => 'Maria <b>Teste</b>', 'mensagem' => '<script>alert(1)</script>'])->assertOK();

        $this->assertSame($this->dono['account']->email, $capturado['to']);
        $this->assertStringContainsString('Casa Validação Lead', $capturado['message']);
        $this->assertStringNotContainsString('<script>', $capturado['message']);
        $this->assertStringNotContainsString('<b>Teste</b>', $capturado['message']);
    }
}
