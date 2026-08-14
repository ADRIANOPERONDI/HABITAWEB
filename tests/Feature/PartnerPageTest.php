<?php

namespace Tests\Feature;

use App\Entities\PlanFeature;
use App\Models\PlanModel;
use App\Models\PropertyModel;
use App\Models\SubscriptionModel;
use App\Services\AccountService;
use App\Services\PlanGate;
use App\Services\PropertyService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre os três bugs reais corrigidos na Fase 5 (página da imobiliária):
 * `listPublicPartners` não filtrava por assinatura vigente, `home.php`
 * linkava para uma rota inexistente (`anunciante/{id}`), e
 * `PartnerController::index()` contava imóveis por parceiro com uma query
 * por parceiro (N+1) em vez de uma única query em lote.
 */
final class PartnerPageTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlanGate::flushMemo();
        cache()->clean();
    }

    /** Mesmo padrão de FeaturedPartnersTest::makeAccountComLogo(). */
    private function featuredPartner(string $nome): int
    {
        $planId = (int) model(PlanModel::class)->insert([
            'chave'           => 'VITRINE_' . bin2hex(random_bytes(4)),
            'nome'            => 'Plano Vitrine ' . bin2hex(random_bytes(4)),
            'preco_mensal'    => 990.00,
            'exposure_weight' => 0,
            'ativo'           => true,
            'features'        => [PlanFeature::EXPOSICAO_VITRINE => true],
        ], true);

        $accountId = (int) (new TenantFactory())->create(['nome' => $nome, 'logo' => 'logos/' . bin2hex(random_bytes(4)) . '.png'])['account']->id;

        model(SubscriptionModel::class)
            ->where('account_id', $accountId)
            ->set(['plan_id' => $planId, 'status' => 'ACTIVE'])
            ->update();

        PlanGate::forget($accountId);

        return $accountId;
    }

    private function property(int $accountId): int
    {
        return (int) model(PropertyModel::class)->insert([
            'account_id'   => $accountId,
            'titulo'       => 'Imovel Parceiro',
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 400000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], true);
    }

    public function testListPublicPartnersExcluiContaSemAssinaturaVigente(): void
    {
        $comAssinatura = (new TenantFactory())->create(['nome' => 'Com Assinatura Vigente Ltda']);

        $semAssinatura = (new TenantFactory())->create(['nome' => 'Assinatura Cancelada Ltda']);
        model(SubscriptionModel::class)
            ->where('account_id', $semAssinatura['account']->id)
            ->set(['status' => 'CANCELLED'])
            ->update();

        $ids = array_map(
            static fn ($p) => (int) $p->id,
            (new AccountService())->listPublicPartners(50)['partners']
        );

        $this->assertContains((int) $comAssinatura['account']->id, $ids);
        $this->assertNotContains((int) $semAssinatura['account']->id, $ids);
    }

    public function testCountPublicPropertiesByAccountsBateComAVersaoSingularPorConta(): void
    {
        $tenantA = (new TenantFactory())->create(['nome' => 'Parceiro A ' . bin2hex(random_bytes(3))]);
        $tenantB = (new TenantFactory())->create(['nome' => 'Parceiro B ' . bin2hex(random_bytes(3))]);

        $accountIdA = (int) $tenantA['account']->id;
        $accountIdB = (int) $tenantB['account']->id;

        $this->property($accountIdA);
        $this->property($accountIdA);
        $this->property($accountIdB);

        $service = new PropertyService();

        $batch = $service->countPublicPropertiesByAccounts([$accountIdA, $accountIdB]);

        $this->assertSame($service->countPublicPropertiesByAccount($accountIdA), $batch[$accountIdA] ?? 0);
        $this->assertSame($service->countPublicPropertiesByAccount($accountIdB), $batch[$accountIdB] ?? 0);
        $this->assertSame(2, $batch[$accountIdA]);
        $this->assertSame(1, $batch[$accountIdB]);
    }

    public function testCountPublicPropertiesByAccountsOmiteContaSemImovelNoResultado(): void
    {
        $tenant = (new TenantFactory())->create(['nome' => 'Parceiro Sem Imovel ' . bin2hex(random_bytes(3))]);
        $accountId = (int) $tenant['account']->id;

        $batch = (new PropertyService())->countPublicPropertiesByAccounts([$accountId]);

        // Sem GROUP BY casando nenhuma linha, a conta simplesmente não entra
        // no array — o chamador precisa usar `?? 0`, não presumir a chave.
        $this->assertArrayNotHasKey($accountId, $batch);
    }

    public function testCountPublicPropertiesByAccountsComListaVaziaNaoConsultaOBanco(): void
    {
        $this->assertSame([], (new PropertyService())->countPublicPropertiesByAccounts([]));
    }

    public function testHomeLinkaParceiroParaARotaCorreta(): void
    {
        $accountId = $this->featuredPartner('Parceiro Home ' . bin2hex(random_bytes(3)));
        $this->property($accountId);

        $html = $this->get('/')->getBody();

        $this->assertStringContainsString('parceiro/' . $accountId, $html);
        $this->assertStringNotContainsString('anunciante/' . $accountId, $html);
    }

    public function testPartnerShowNuncaUsaOPlaceholderExternoHardcoded(): void
    {
        $tenant = (new TenantFactory())->create(['nome' => 'Parceiro Capa ' . bin2hex(random_bytes(3))]);
        $this->property((int) $tenant['account']->id);

        $html = $this->get('parceiro/' . $tenant['account']->id)->getBody();

        // Sem capa cadastrada, cai no placeholder LOCAL (assets/img/...),
        // nunca mais no https://placehold.co/... hardcoded.
        $this->assertStringNotContainsString('placehold.co', $html);
        $this->assertStringContainsString('assets/img/placeholder-house.png', $html);
    }
}
