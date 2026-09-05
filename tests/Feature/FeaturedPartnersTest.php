<?php

namespace Tests\Feature;

use App\Entities\PlanFeature;
use App\Models\AccountModel;
use App\Models\PaymentTransactionModel;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use App\Services\AccountService;
use App\Services\PlanGate;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre `AccountService::getFeaturedPartners()` — a vitrine "Imobiliárias em
 * destaque" da home.
 *
 * Antes, "destaque" era só ter logo cadastrado: qualquer conta, de qualquer
 * plano, até inadimplente, aparecia. Passa a exigir a feature
 * `exposicao.vitrine` do plano vigente (Ouro/Diamante da proposta comercial)
 * e reusa o bloqueio por atraso que já protege a busca pública de imóvel.
 */
final class FeaturedPartnersTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlanGate::flushMemo();
        cache()->clean();
    }

    private function makePlan(bool $comVitrine, int $exposureWeight = 0): int
    {
        $model = model(PlanModel::class);
        $model->insert([
            'chave'           => 'VITRINE_' . bin2hex(random_bytes(4)),
            'nome'            => 'Plano Vitrine ' . bin2hex(random_bytes(4)),
            'preco_mensal'    => 990.00,
            'exposure_weight' => $exposureWeight,
            'ativo'           => true,
            'features'        => $comVitrine ? [PlanFeature::EXPOSICAO_VITRINE => true] : [],
        ]);

        return (int) $model->getInsertID();
    }

    private function makeAccountComLogo(int $planId, string $nome): int
    {
        $accountId = (int) (new TenantFactory())->create(['nome' => $nome, 'logo' => 'logos/' . bin2hex(random_bytes(4)) . '.png'])['account']->id;

        model(SubscriptionModel::class)
            ->where('account_id', $accountId)
            ->set(['plan_id' => $planId, 'status' => 'ACTIVE'])
            ->update();

        PlanGate::forget($accountId);

        return $accountId;
    }

    public function testPlanoSemAFeatureFicaFora(): void
    {
        $semVitrine = $this->makeAccountComLogo($this->makePlan(false), 'Sem Vitrine Ltda');

        $ids = array_map(static fn ($p) => (int) $p->id, (new AccountService())->getFeaturedPartners(20));

        $this->assertNotContains($semVitrine, $ids);
    }

    public function testPlanoComAFeatureAparece(): void
    {
        $comVitrine = $this->makeAccountComLogo($this->makePlan(true), 'Com Vitrine Ltda');

        $ids = array_map(static fn ($p) => (int) $p->id, (new AccountService())->getFeaturedPartners(20));

        $this->assertContains($comVitrine, $ids);
    }

    public function testContaComFaturaVencidaHaMaisDeTresDiasFicaFora(): void
    {
        $accountId = $this->makeAccountComLogo($this->makePlan(true), 'Inadimplente Ltda');

        model(PaymentTransactionModel::class)->insert([
            'account_id'             => $accountId,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => 'pay_' . bin2hex(random_bytes(6)),
            'amount'                 => 990.00,
            'status'                 => 'OVERDUE',
            'due_date'               => date('Y-m-d', strtotime('-10 days')),
        ]);
        \App\Services\PublicPropertyVisibilityService::invalidateCaches();

        $ids = array_map(static fn ($p) => (int) $p->id, (new AccountService())->getFeaturedPartners(20));

        $this->assertNotContains($accountId, $ids, 'Bloqueado por atraso não pode continuar na vitrine institucional.');
    }

    public function testSemAssinaturaVigenteFicaFora(): void
    {
        $accountId = (int) (new TenantFactory())->create(['nome' => 'Sem Assinatura Ltda', 'logo' => 'logos/x.png'])['account']->id;

        model(SubscriptionModel::class)->where('account_id', $accountId)->set(['status' => 'CANCELLED'])->update();

        $ids = array_map(static fn ($p) => (int) $p->id, (new AccountService())->getFeaturedPartners(20));

        $this->assertNotContains($accountId, $ids);
    }

    public function testOrdenaPeloPesoDeExposicaoDoPlano(): void
    {
        $diamante = $this->makeAccountComLogo($this->makePlan(true, 20), 'Diamante Vitrine');
        $ouro     = $this->makeAccountComLogo($this->makePlan(true, 10), 'Ouro Vitrine');

        $ids = array_map(static fn ($p) => (int) $p->id, (new AccountService())->getFeaturedPartners(20));

        $posDiamante = array_search($diamante, $ids, true);
        $posOuro     = array_search($ouro, $ids, true);

        $this->assertNotFalse($posDiamante);
        $this->assertNotFalse($posOuro);
        $this->assertLessThan($posOuro, $posDiamante, 'exposure_weight maior (Diamante) vem antes do menor (Ouro).');
    }

    public function testContaAdministradorNuncaAparece(): void
    {
        model(AccountModel::class)->where('nome', 'Administrador')->countAllResults();
        // Não força a existência de "Administrador" — só confere que, SE
        // alguma conta com esse nome exato tivesse a feature, o filtro por
        // nome ainda a excluiria. Regressão simples e barata do WHERE atual.
        $comNomeAdmin = $this->makeAccountComLogo($this->makePlan(true), 'Administrador');

        $ids = array_map(static fn ($p) => (int) $p->id, (new AccountService())->getFeaturedPartners(20));

        $this->assertNotContains($comNomeAdmin, $ids);
    }
}
