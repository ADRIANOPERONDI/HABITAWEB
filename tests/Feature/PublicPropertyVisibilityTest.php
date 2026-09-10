<?php

namespace Tests\Feature;

use App\Models\PaymentTransactionModel;
use App\Models\PropertyModel;
use App\Models\AccountModel;
use App\Services\PropertyService;
use App\Services\PublicPropertyVisibilityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

final class PublicPropertyVisibilityTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PublicPropertyVisibilityService::invalidateCaches();
    }

    protected function tearDown(): void
    {
        PublicPropertyVisibilityService::invalidateCaches();
        parent::tearDown();
    }

    private function insertProperty(int $accountId, string $suffix): int
    {
        $model = new PropertyModel();
        $model->insert([
            'account_id'      => $accountId,
            'tipo_negocio'    => 'VENDA',
            'tipo_imovel'     => 'CASA',
            'titulo'          => "Imóvel público {$suffix}",
            'cidade'          => "Cidade {$suffix}",
            'bairro'          => "Bairro {$suffix}",
            'preco'           => 700000,
            'latitude'        => -26.72,
            'longitude'       => -53.51,
            'status'          => 'ACTIVE',
            'is_destaque'     => true,
            'highlight_level' => 1,
            'score_qualidade' => 100,
        ]);

        return (int) $model->getInsertID();
    }

    private function ids(array $properties): array
    {
        return array_map(static fn ($property) => (int) $property->id, $properties);
    }

    public function testSoftDeletedPropertyIsAbsentFromEveryPublicQuery(): void
    {
        $tenant = (new TenantFactory())->create();
        $id = $this->insertProperty((int) $tenant['account']->id, 'SoftDelete_' . uniqid());
        (new PropertyModel())->delete($id);

        $service = new PropertyService();

        $this->assertNotContains($id, $this->ids($service->getFeaturedProperties(50)));
        $this->assertNotContains($id, $this->ids($service->getSponsoredPool(50)));
        $this->assertNotContains($id, $this->ids($service->searchMapPins([], 50)));
        $this->assertNull($service->getPublicPropertyDetails($id));
        $this->assertNotNull($service->getPropertyWithDeleted($id));
    }

    public function testFinanciallyBlockedPropertyIsPubliclyHiddenButInternallyAvailable(): void
    {
        $tenant = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;
        $suffix = 'Overdue_' . uniqid();
        $id = $this->insertProperty($accountId, $suffix);

        (new PaymentTransactionModel())->insert([
            'account_id'             => $accountId,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => 'pay_' . uniqid(),
            'amount'                 => 100,
            'status'                 => 'OVERDUE',
            'due_date'               => date('Y-m-d', strtotime('-10 days')),
        ]);

        $service = new PropertyService();

        $this->assertNotContains($id, $this->ids($service->getFeaturedProperties(50)));
        $this->assertNotContains($id, $this->ids($service->getSponsoredPool(50)));
        $this->assertNotContains($id, $this->ids($service->searchMapPins([], 50)));
        $this->assertNull($service->getPublicPropertyDetails($id));
        $this->assertSame(0, $service->countPublicPropertiesByAccount($accountId));

        $internal = $service->getPropertyDetails($id);
        $this->assertNotNull($internal);
        $this->assertSame($id, (int) $internal['property']->id);

        $options = $service->getSearchFilterOptions();
        $cities = array_map(static fn ($row) => $row->cidade, $options['cidades']);
        $this->assertNotContains("Cidade {$suffix}", $cities);
    }

    public function testPublicDetailReturns404ForBlockedProperty(): void
    {
        $tenant = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;
        $id = $this->insertProperty($accountId, 'Route_' . uniqid());

        (new PaymentTransactionModel())->insert([
            'account_id'             => $accountId,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => 'pay_route_' . uniqid(),
            'amount'                 => 100,
            'status'                 => 'PENDING',
            'due_date'               => date('Y-m-d', strtotime('-10 days')),
        ]);

        // Agora existe um handler de 404 registrado (set404Override em
        // app/Config/Routes.php, para servir JSON nas rotas de API), então o
        // PageNotFoundException é tratado e vira uma resposta 404 de verdade em
        // vez de escapar como exceção. O comportamento observável — imóvel
        // bloqueado não é acessível publicamente — é o mesmo.
        $this->get("imovel/{$id}")->assertStatus(404);
    }

    public function testPropertyFromDeletedAccountIsPubliclyHidden(): void
    {
        $tenant = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;
        $id = $this->insertProperty($accountId, 'DeletedAccount_' . uniqid());

        (new AccountModel())->delete($accountId);

        $service = new PropertyService();
        $this->assertNotContains($id, $this->ids($service->getFeaturedProperties(50)));
        $this->assertNull($service->getPublicPropertyDetails($id));
    }

    // ---- contato (WhatsApp/telefone) exige conta totalmente onboardada -----

    /**
     * Regressão: `PublicPropertyVisibilityService` nunca checou KYC/assinatura
     * — só fatura vencida. Uma conta que perdesse a assinatura ativa depois de
     * já ter cadastrado imóveis (ex.: via sync do Simob, que não passa pelo
     * AdminAuth) continuava com WhatsApp/telefone funcionando no site público.
     * O anúncio em si continua visível — só o canal de contato deve sumir.
     */
    public function testTelefoneEWhatsappOcultosParaContaSemAssinaturaAtiva(): void
    {
        $tenant = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;
        $id = $this->insertProperty($accountId, 'SemAssinatura_' . uniqid());

        \Config\Database::connect()->table('subscriptions')
            ->where('account_id', $accountId)
            ->delete();

        $response = $this->get("imovel/{$id}");
        $response->assertOK();
        $response->assertDontSee('11999990000');
        $response->assertSee('Imóvel público');
    }

    public function testTelefoneEWhatsappOcultosParaContaComKycPendente(): void
    {
        $tenant = (new TenantFactory())->create(['verification_status' => 'PENDING', 'is_verified' => false]);
        $accountId = (int) $tenant['account']->id;
        $id = $this->insertProperty($accountId, 'KycPendente_' . uniqid());

        $response = $this->get("imovel/{$id}");
        $response->assertOK();
        $response->assertDontSee('11999990000');
        $response->assertSee('Imóvel público');
    }

    public function testTelefoneEWhatsappVisiveisParaContaTotalmenteOnboarded(): void
    {
        $tenant = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;
        $id = $this->insertProperty($accountId, 'Onboarded_' . uniqid());

        $response = $this->get("imovel/{$id}");
        $response->assertOK();
        $response->assertSee('11999990000');
    }

    public function testGetPublicPropertyDetailsRetornaCamposDeContatoNulosQuandoNaoOnboarded(): void
    {
        $tenant = (new TenantFactory())->create(['verification_status' => 'PENDING', 'is_verified' => false]);
        $accountId = (int) $tenant['account']->id;
        $id = $this->insertProperty($accountId, 'ServiceNulo_' . uniqid());

        $details = (new PropertyService())->getPublicPropertyDetails($id);

        $this->assertNotNull($details);
        $this->assertFalse($details['showContact']);
        $this->assertNull($details['property']->account_phone);
        $this->assertNull($details['property']->account_whatsapp);
        $this->assertNull($details['property']->whatsapp_hub_config);
    }

    public function testGetPropertyDetailsInternoNaoOcultaContatoIndependenteDeOnboarding(): void
    {
        $tenant = (new TenantFactory())->create(['verification_status' => 'PENDING', 'is_verified' => false]);
        $accountId = (int) $tenant['account']->id;
        $id = $this->insertProperty($accountId, 'ServiceInterno_' . uniqid());

        $details = (new PropertyService())->getPropertyDetails($id);

        $this->assertNotNull($details);
        $this->assertSame('11999990000', $details['property']->account_phone);
    }
}
