<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Models\LeadChargeModel;
use App\Models\LeadChargeRuleModel;
use App\Models\LeadModel;
use App\Models\PropertyModel;
use App\Services\LeadChargeService;
use App\Services\LeadQualityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Checagem de qualidade (LeadQualityService) e o ciclo de contestação
 * (contest/resolveDispute/approveExpired) sobre a cobrança por lead recebido.
 */
final class LeadChargeQualityDisputeTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        cache()->clean();
    }

    private function regraVenda(): int
    {
        return (int) model(LeadChargeRuleModel::class)->insert([
            'account_id'    => null,
            'provider_code' => null,
            'tipo_negocio'  => 'VENDA',
            'model'         => LeadChargeRuleModel::MODEL_FIXED,
            'value'         => 80,
            'is_active'     => true,
        ], true);
    }

    private function property(int $accountId): int
    {
        return (int) model(PropertyModel::class)->insert([
            'account_id'   => $accountId,
            'titulo'       => 'Imovel Qualidade',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 500000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);
    }

    private function lead(int $accountId, int $propertyId, array $overrides = []): object
    {
        $id = model(LeadModel::class)->insert(array_merge([
            'property_id'           => $propertyId,
            'account_id_anunciante' => $accountId,
            'nome_visitante'        => 'Visitante',
            'email_visitante'       => 'visitante_' . bin2hex(random_bytes(4)) . '@teste.local',
            'telefone_visitante'    => '48999998888',
            'tipo_lead'             => 'MSG',
            'origem'                => 'SITE',
            'status'                => LeadModel::STATUS_NOVO,
            'tipo_negocio'          => 'VENDA',
        ], $overrides), true);

        return model(LeadModel::class)->find($id);
    }

    // ---------------------------------------------------------- qualidade

    public function testTelefoneCurtoReprovaOLead(): void
    {
        $flags = (new LeadQualityService())->scan((object) [
            'telefone_visitante'    => '123456',
            'email_visitante'       => 'ok@exemplo.com',
            'ip_address'            => '1.2.3.4',
            'account_id_anunciante' => 0,
        ]);

        $this->assertContains('telefone_invalido', $flags);
    }

    public function testEmailDescartavelReprovaOLead(): void
    {
        $flags = (new LeadQualityService())->scan((object) [
            'telefone_visitante'    => '48999998888',
            'email_visitante'       => 'fulano@mailinator.com',
            'ip_address'            => '1.2.3.4',
            'account_id_anunciante' => 0,
        ]);

        $this->assertContains('email_descartavel', $flags);
    }

    public function testLeadLimpoNaoReprova(): void
    {
        $flags = (new LeadQualityService())->scan((object) [
            'telefone_visitante'    => '48999998888',
            'email_visitante'       => 'visitante@gmail.com',
            'ip_address'            => '203.0.113.9',
            'account_id_anunciante' => 0,
        ]);

        $this->assertSame([], $flags);
    }

    public function testAutoLeadDoProprioAnuncianteEReprovado(): void
    {
        $tenant = (new TenantFactory())->create();

        $flags = (new LeadQualityService())->scan((object) [
            'telefone_visitante'    => '48999998888',
            'email_visitante'       => $tenant['account']->email,
            'ip_address'            => '203.0.113.9',
            'account_id_anunciante' => $tenant['account']->id,
        ]);

        $this->assertContains('auto_lead_anunciante', $flags);
    }

    public function testRajadaDoMesmoIpReprova(): void
    {
        $tenant     = (new TenantFactory())->create();
        $propertyId = $this->property($tenant['account']->id);
        $ip         = '198.51.100.7';

        // Tres mensagens anteriores do mesmo IP nos ultimos minutos ja
        // alcancam o limiar sozinhas — o scan() abaixo roda sobre um objeto
        // solto (nao inserido), entao so as previamente gravadas contam.
        $this->lead($tenant['account']->id, $propertyId, ['ip_address' => $ip]);
        $this->lead($tenant['account']->id, $propertyId, ['ip_address' => $ip]);
        $this->lead($tenant['account']->id, $propertyId, ['ip_address' => $ip]);

        $flags = (new LeadQualityService())->scan((object) [
            'telefone_visitante'    => '48999998888',
            'email_visitante'       => 'visitante@gmail.com',
            'ip_address'            => $ip,
            'account_id_anunciante' => $tenant['account']->id,
        ]);

        $this->assertContains('rajada_mesmo_ip', $flags);
    }

    /** Lead reprovado nasce WAIVED direto — nunca passa por resolução de regra. */
    public function testLeadReprovadoNasceWaivedENaoAlcancaAFatura(): void
    {
        $tenant = (new TenantFactory())->create();
        $this->regraVenda();

        $propertyId = $this->property($tenant['account']->id);
        $lead       = $this->lead($tenant['account']->id, $propertyId, [
            'email_visitante' => 'fulano@mailinator.com',
        ]);

        $id = (new LeadChargeService())->onLeadReceived($lead);

        $this->assertNotNull($id);

        $charge = model(LeadChargeModel::class)->find($id);

        $this->assertSame(LeadChargeModel::STATUS_WAIVED, $charge->status);
        $this->assertSame(0.0, $charge->commission_value);
        $this->assertStringContainsString('email_descartavel', $charge->waived_reason);
    }

    // ------------------------------------------------------------ disputa

    private function gerarCobrancaPendente(): array
    {
        $tenant = (new TenantFactory())->create();
        $this->regraVenda();

        $propertyId = $this->property($tenant['account']->id);
        $lead       = $this->lead($tenant['account']->id, $propertyId);

        $id = (new LeadChargeService())->onLeadReceived($lead);

        return [$tenant, $id];
    }

    public function testTenantContestaDentroDoPrazo(): void
    {
        [$tenant, $id] = $this->gerarCobrancaPendente();

        $ok = (new LeadChargeService())->contest($id, (int) $tenant['account']->id, 'Nao reconheco este lead');

        $this->assertTrue($ok);
        $this->assertSame(LeadChargeModel::STATUS_DISPUTED, model(LeadChargeModel::class)->find($id)->status);
    }

    public function testOutraContaNaoConseguerContestar(): void
    {
        [, $id] = $this->gerarCobrancaPendente();
        $outraConta = (new TenantFactory())->create();

        $ok = (new LeadChargeService())->contest($id, (int) $outraConta['account']->id, 'Nao e meu');

        $this->assertFalse($ok);
    }

    public function testDisputaProcedenteIsentaACobranca(): void
    {
        [$tenant, $id] = $this->gerarCobrancaPendente();
        $service = new LeadChargeService();
        $service->contest($id, (int) $tenant['account']->id, 'Numero errado');

        $ok = $service->resolveDispute($id, true, 'Confirmado, numero de outra pessoa');

        $this->assertTrue($ok);
        $charge = model(LeadChargeModel::class)->find($id);
        $this->assertSame(LeadChargeModel::STATUS_WAIVED, $charge->status);
    }

    public function testDisputaImprocedenteAprovaACobranca(): void
    {
        [$tenant, $id] = $this->gerarCobrancaPendente();
        $service = new LeadChargeService();
        $service->contest($id, (int) $tenant['account']->id, 'Nao reconheco');

        $ok = $service->resolveDispute($id, false, 'Lead confirmado no CRM');

        $this->assertTrue($ok);
        $charge = model(LeadChargeModel::class)->find($id);
        $this->assertSame(LeadChargeModel::STATUS_APPROVED, $charge->status);
    }

    // --------------------------------------------------------- aprovacao

    public function testAprovaAutomaticamenteQuemPassouDoPrazo(): void
    {
        [, $id] = $this->gerarCobrancaPendente();

        model(LeadChargeModel::class)->update($id, [
            'contest_deadline' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $n = (new LeadChargeService())->approveExpired();

        $this->assertSame(1, $n);
        $this->assertSame(LeadChargeModel::STATUS_APPROVED, model(LeadChargeModel::class)->find($id)->status);
    }

    public function testNaoAprovaQuemAindaEstaDentroDoPrazo(): void
    {
        [, $id] = $this->gerarCobrancaPendente();

        $n = (new LeadChargeService())->approveExpired();

        $this->assertSame(0, $n);
        $this->assertSame(LeadChargeModel::STATUS_PENDING, model(LeadChargeModel::class)->find($id)->status);
    }
}
