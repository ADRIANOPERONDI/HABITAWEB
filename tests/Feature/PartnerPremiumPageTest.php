<?php

namespace Tests\Feature;

use App\Entities\PlanFeature;
use App\Models\AccountModel;
use App\Models\PlanModel;
use App\Models\PropertyModel;
use App\Models\SubscriptionModel;
use App\Models\UserModel;
use App\Services\PlanGate;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre a etapa 5.4: a página premium (`pagina.premium`, hoje só Diamante)
 * só aparece pra quem tem a feature — quem não tem continua vendo a página
 * básica de sempre (`web/partners/show.php`). Cobre também as três coisas
 * que só existem na versão premium: equipe pública (opt-in por corretor via
 * `users.publico`), e as abas Lançamentos/Destaques sobre o mesmo WHERE base
 * da listagem pública.
 */
final class PartnerPremiumPageTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlanGate::flushMemo();
        cache()->clean();
    }

    private function tenantComFeature(bool $comPaginaPremium, string $nome): array
    {
        $planId = (int) model(PlanModel::class)->insert([
            'chave'        => 'PREMIUM_' . bin2hex(random_bytes(4)),
            'nome'         => 'Plano Premium ' . bin2hex(random_bytes(4)),
            'preco_mensal' => 990.00,
            'ativo'        => true,
            'features'     => $comPaginaPremium ? [PlanFeature::PAGINA_PREMIUM => true] : [],
        ], true);

        $tenant = (new TenantFactory())->create(['nome' => $nome]);
        $accountId = (int) $tenant['account']->id;

        model(SubscriptionModel::class)
            ->where('account_id', $accountId)
            ->set(['plan_id' => $planId, 'status' => 'ACTIVE'])
            ->update();

        PlanGate::forget($accountId);

        return $tenant;
    }

    private function property(int $accountId, array $overrides = []): int
    {
        return (int) model(PropertyModel::class)->insert(array_merge([
            'account_id'   => $accountId,
            'titulo'       => 'Imovel Premium ' . bin2hex(random_bytes(3)),
            'tipo_negocio' => 'VENDA',
            'tipo_imovel'  => 'APARTAMENTO',
            'preco'        => 400000,
            'cidade'       => 'Chapecó',
            'bairro'       => 'Centro',
            'estado'       => 'SC',
            'status'       => 'ACTIVE',
        ], $overrides), true);
    }

    private function slugOf(int $accountId): string
    {
        return model(AccountModel::class)->find($accountId)->slug;
    }

    public function testContaSemAFeatureVeAPaginaBasica(): void
    {
        $tenant = $this->tenantComFeature(false, 'Sem Pagina Premium ' . bin2hex(random_bytes(3)));
        $accountId = (int) $tenant['account']->id;
        $this->property($accountId);

        $html = $this->get('imobiliaria/' . $this->slugOf($accountId))->getBody();

        // 'aba=lancamentos' em vez do texto acentuado do link ("Lançamentos")
        // -- a saída HTML renderizada troca acento por entidade
        // (Lan&ccedil;amentos), então o literal UTF-8 nunca bate contra o
        // corpo da resposta real.
        $this->assertStringNotContainsString('aba=lancamentos', $html);
        $this->assertStringNotContainsString('nav-pills', $html);
    }

    public function testContaComAFeatureVeAsAbasDaPaginaPremium(): void
    {
        $tenant = $this->tenantComFeature(true, 'Com Pagina Premium ' . bin2hex(random_bytes(3)));
        $accountId = (int) $tenant['account']->id;
        $this->property($accountId);

        $html = $this->get('imobiliaria/' . $this->slugOf($accountId))->getBody();

        $this->assertStringContainsString('aba=lancamentos', $html);
        $this->assertStringContainsString('aba=destaques', $html);
        $this->assertStringContainsString('Destaques', $html);
    }

    public function testAbaLancamentosMostraSoImovelIsNovo(): void
    {
        $tenant = $this->tenantComFeature(true, 'Lancamentos ' . bin2hex(random_bytes(3)));
        $accountId = (int) $tenant['account']->id;

        $this->property($accountId, ['titulo' => 'Imovel Antigo Nao Deve Aparecer', 'is_novo' => false]);
        $this->property($accountId, ['titulo' => 'Imovel Lancamento Deve Aparecer', 'is_novo' => true]);

        $html = $this->get('imobiliaria/' . $this->slugOf($accountId) . '?aba=lancamentos')->getBody();

        $this->assertStringContainsString('Imovel Lancamento Deve Aparecer', $html);
        $this->assertStringNotContainsString('Imovel Antigo Nao Deve Aparecer', $html);
    }

    public function testAbaDestaquesMostraSoImovelComDestaque(): void
    {
        $tenant = $this->tenantComFeature(true, 'Destaques ' . bin2hex(random_bytes(3)));
        $accountId = (int) $tenant['account']->id;

        $this->property($accountId, ['titulo' => 'Imovel Comum Nao Deve Aparecer', 'is_destaque' => false]);
        $this->property($accountId, ['titulo' => 'Imovel Destaque Deve Aparecer', 'is_destaque' => true]);

        $html = $this->get('imobiliaria/' . $this->slugOf($accountId) . '?aba=destaques')->getBody();

        $this->assertStringContainsString('Imovel Destaque Deve Aparecer', $html);
        $this->assertStringNotContainsString('Imovel Comum Nao Deve Aparecer', $html);
    }

    public function testEquipePublicaMostraSoQuemOptouEEstaAtivo(): void
    {
        $tenant = $this->tenantComFeature(true, 'Equipe ' . bin2hex(random_bytes(3)));
        $accountId = (int) $tenant['account']->id;
        $userModel = model(UserModel::class);

        $userModel->insert([
            'username'   => 'publico_' . bin2hex(random_bytes(3)),
            'nome'       => 'Corretora Publica Visivel',
            'account_id' => $accountId,
            'active'     => 1,
            'publico'    => true,
            'cargo'      => 'Corretora Associada',
        ]);

        $userModel->insert([
            'username'   => 'privado_' . bin2hex(random_bytes(3)),
            'nome'       => 'Corretor Privado Invisivel',
            'account_id' => $accountId,
            'active'     => 1,
            'publico'    => false,
        ]);

        $userModel->insert([
            'username'   => 'inativo_' . bin2hex(random_bytes(3)),
            'nome'       => 'Corretor Inativo Invisivel',
            'account_id' => $accountId,
            'active'     => 0,
            'publico'    => true,
        ]);

        $html = $this->get('imobiliaria/' . $this->slugOf($accountId))->getBody();

        $this->assertStringContainsString('Corretora Publica Visivel', $html);
        $this->assertStringNotContainsString('Corretor Privado Invisivel', $html);
        $this->assertStringNotContainsString('Corretor Inativo Invisivel', $html);
    }

    public function testSemLatitudeLongitudeNaoRendorizaOMapa(): void
    {
        $tenant = $this->tenantComFeature(true, 'Sem Mapa ' . bin2hex(random_bytes(3)));
        $accountId = (int) $tenant['account']->id;

        $html = $this->get('imobiliaria/' . $this->slugOf($accountId))->getBody();

        $this->assertStringNotContainsString('partnerMap', $html);
    }

    public function testComLatitudeLongitudeRendorizaOMapa(): void
    {
        $tenant = $this->tenantComFeature(true, 'Com Mapa ' . bin2hex(random_bytes(3)));
        $accountId = (int) $tenant['account']->id;

        model(AccountModel::class)->update($accountId, ['latitude' => -27.0965, 'longitude' => -52.6151]);

        $html = $this->get('imobiliaria/' . $this->slugOf($accountId))->getBody();

        $this->assertStringContainsString('partnerMap', $html);
    }
}
