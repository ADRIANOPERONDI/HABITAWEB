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
 * A vitrine "Imobiliárias em destaque" da home fica em cache por 1 h
 * (`home_partners`, ver Home::index). Até aqui ninguém apagava essa chave: o
 * tenant fazia upgrade para Ouro e seguia fora da home por até uma hora —
 * exatamente o "não apareceu" que vira chamado. Agora qualquer mudança em
 * conta, assinatura, plano ou fatura derruba o cache na hora.
 */
final class FeaturedPartnersCacheTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlanGate::flushMemo();
        cache()->clean();
    }

    /**
     * Grava um sentinela e prova que ele é legível antes de qualquer
     * asserção de "sumiu" — sem isso um handler de cache que não grava nada
     * faria todos os testes passarem à toa.
     */
    private function primeCache(): void
    {
        cache()->save(AccountService::FEATURED_PARTNERS_CACHE_KEY, ['sentinela'], 300);
        $this->assertSame(['sentinela'], cache()->get(AccountService::FEATURED_PARTNERS_CACHE_KEY));
    }

    private function assertCacheGone(string $motivo): void
    {
        $this->assertNull(cache()->get(AccountService::FEATURED_PARTNERS_CACHE_KEY), $motivo);
    }

    public function testChaveEAMesmaQueAHomeUsa(): void
    {
        $this->assertSame('home_partners', AccountService::FEATURED_PARTNERS_CACHE_KEY);
    }

    public function testForgetApagaAChaveDiretamente(): void
    {
        $this->primeCache();

        AccountService::forgetFeaturedPartnersCache();

        $this->assertCacheGone('forgetFeaturedPartnersCache() precisa apagar a chave.');
    }

    public function testMudancaNaAssinaturaDerrubaOCache(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;
        $this->primeCache();

        model(SubscriptionModel::class)
            ->where('account_id', $accountId)
            ->set(['status' => 'CANCELLED'])
            ->update();

        $this->assertCacheGone('Cancelar, ativar ou trocar assinatura precisa refletir na vitrine na hora.');
    }

    public function testMudancaNoPlanoDerrubaOCache(): void
    {
        $planModel = model(PlanModel::class);
        $planId = (int) $planModel->insert([
            'chave'           => 'VITRINE_CACHE_' . bin2hex(random_bytes(4)),
            'nome'            => 'Plano Cache ' . bin2hex(random_bytes(4)),
            'preco_mensal'    => 990.00,
            'exposure_weight' => 0,
            'ativo'           => true,
            'features'        => [],
        ], true);
        $this->primeCache();

        // É o que o superadmin faz ao marcar "Imobiliárias em destaque" no plano.
        $planModel->update($planId, ['features' => [PlanFeature::EXPOSICAO_VITRINE => true]]);

        $this->assertCacheGone('Marcar/desmarcar a feature do plano precisa refletir na vitrine na hora.');
    }

    public function testMudancaNaContaDerrubaOCache(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;
        $this->primeCache();

        model(AccountModel::class)->update($accountId, ['status' => 'SUSPENDED']);

        $this->assertCacheGone('Suspender/reativar a conta precisa refletir na vitrine na hora.');
    }

    public function testFaturaVencidaDerrubaOCache(): void
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;
        $this->primeCache();

        model(PaymentTransactionModel::class)->insert([
            'account_id'             => $accountId,
            'gateway'                => 'asaas',
            'gateway_transaction_id' => 'pay_' . bin2hex(random_bytes(6)),
            'amount'                 => 990.00,
            'status'                 => 'OVERDUE',
            'due_date'               => date('Y-m-d', strtotime('-10 days')),
        ]);

        $this->assertCacheGone('Fatura em atraso entra no bloqueio da vitrine, então precisa derrubar o cache.');
    }
}
