<?php

namespace Tests\Feature;

use App\Database\Seeds\PlanSeeder;
use App\Entities\PlanFeature;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre o PlanSeeder, que passou de DELETE + INSERT para upsert por chave.
 *
 * O teste que mais importa é o da assinatura sobrevivente: `subscriptions.plan_id`
 * tem FK com ON DELETE CASCADE, então o `DELETE FROM plans` da versão antiga
 * apagava as assinaturas dos clientes junto. Rodar o seeder num banco povoado
 * era destrutivo e nada avisava.
 */
final class PlanSeederTest extends HabitawebTestCase
{
    /** Nome próprio: `seed()` já existe (public) no DatabaseTestTrait da base. */
    private function semear(): void
    {
        $this->seed(PlanSeeder::class);
    }

    private function plan(string $chave)
    {
        return model(PlanModel::class)->where('chave', $chave)->first();
    }

    public function testCriaOsTresPlanosComerciais(): void
    {
        $this->semear();

        $prata    = $this->plan('PRATA');
        $ouro     = $this->plan('OURO');
        $diamante = $this->plan('DIAMANTE');

        $this->assertSame(990.0, (float) $prata->preco_mensal);
        $this->assertSame(1690.0, (float) $ouro->preco_mensal);
        $this->assertSame(2490.0, (float) $diamante->preco_mensal);

        // Nenhum plano limita estoque: é decisão comercial deliberada.
        $this->assertTrue($prata->isIlimitadoImoveis());
        $this->assertTrue($ouro->isIlimitadoImoveis());
        $this->assertTrue($diamante->isIlimitadoImoveis());
    }

    public function testCotaDeTurbinadaPorPlano(): void
    {
        $this->semear();

        $this->assertSame(0, $this->plan('PRATA')->turbosIncluidos());
        $this->assertSame(5, $this->plan('OURO')->turbosIncluidos());
        $this->assertSame(10, $this->plan('DIAMANTE')->turbosIncluidos());
    }

    public function testBonusDeTurbinadaDoCicloAnual(): void
    {
        $this->semear();

        $this->assertSame(2, $this->plan('PRATA')->turboBonusAnual());
        $this->assertSame(3, $this->plan('OURO')->turboBonusAnual());
        $this->assertSame(5, $this->plan('DIAMANTE')->turboBonusAnual());
    }

    public function testCreditoDeLeadsPorPlano(): void
    {
        $this->semear();

        $this->assertSame(0.0, $this->plan('PRATA')->creditoLeadsMensal());
        $this->assertSame(200.0, $this->plan('OURO')->creditoLeadsMensal());
        $this->assertSame(500.0, $this->plan('DIAMANTE')->creditoLeadsMensal());
    }

    public function testFeaturesPorPlano(): void
    {
        $this->semear();

        $prata    = $this->plan('PRATA');
        $ouro     = $this->plan('OURO');
        $diamante = $this->plan('DIAMANTE');

        $this->assertFalse($prata->has(PlanFeature::PAINEL_COMPLETO));
        $this->assertTrue($ouro->has(PlanFeature::PAINEL_COMPLETO));
        $this->assertTrue($ouro->has(PlanFeature::EXPOSICAO_BUSCA));

        $this->assertFalse($ouro->has(PlanFeature::PAGINA_PREMIUM));
        $this->assertTrue($diamante->has(PlanFeature::PAGINA_PREMIUM));
        // P4: a tela de Inteligência de Mercado ainda não existe (fase 2) —
        // conceder a feature sem nenhuma tela pra mostrar venderia o que não
        // se entrega. COMPARATIVO_MERCADO, que já tem uso previsto, continua.
        $this->assertFalse($diamante->has(PlanFeature::INTELIGENCIA_MERCADO));
        $this->assertTrue($diamante->has(PlanFeature::COMPARATIVO_MERCADO));
    }

    public function testPlanoAnualCobraDezMensalidades(): void
    {
        $this->semear();

        foreach (['PRATA' => 990.0, 'OURO' => 1690.0, 'DIAMANTE' => 2490.0] as $chave => $mensal) {
            $plan = $this->plan($chave);
            $this->assertSame(
                $mensal * 10,
                (float) $plan->preco_anual,
                "{$chave}: o anual deve ser o total do ciclo (paga 10, usa 12)."
            );
        }
    }

    public function testRodarDuasVezesNaoDuplicaNemRenomeiaDeNovo(): void
    {
        $this->semear();
        $this->semear();

        foreach (['PRATA', 'OURO', 'DIAMANTE'] as $chave) {
            $this->assertSame(
                1,
                model(PlanModel::class)->where('chave', $chave)->countAllResults(),
                "{$chave} duplicou."
            );
        }

        $this->assertSame(
            0,
            model(PlanModel::class)->like('chave', '_LEGADO_LEGADO')->countAllResults()
        );
    }

    public function testAssinaturaExistenteSobreviveAoSeeder(): void
    {
        $tenant    = (new TenantFactory())->create();
        $accountId = (int) $tenant['account']->id;

        $antes = model(SubscriptionModel::class)->where('account_id', $accountId)->countAllResults();
        $this->assertGreaterThan(0, $antes, 'A fixture precisa ter criado uma assinatura.');

        $this->semear();

        $depois = model(SubscriptionModel::class)->where('account_id', $accountId)->countAllResults();

        $this->assertSame(
            $antes,
            $depois,
            'subscriptions.plan_id tem FK ON DELETE CASCADE: um DELETE FROM plans levaria as assinaturas junto.'
        );
    }

    public function testPlanoAntigoVirouLegadoDesativadoSemPerderRecurso(): void
    {
        // Simula o estado de produção antes da virada comercial.
        model(PlanModel::class)->where('chave', 'PRATA')->set(['preco_mensal' => 1850.00])->update();

        $this->semear();

        $legado = $this->plan('PRATA_LEGADO');

        $this->assertNotNull($legado, 'O plano antigo precisa sobreviver sob outra chave.');
        $this->assertFalse((bool) $legado->ativo, 'Legado não pode continuar à venda.');
        $this->assertSame(1850.0, (float) $legado->preco_mensal);

        // E a chave PRATA passa a ser o plano novo.
        $this->assertSame(990.0, (float) $this->plan('PRATA')->preco_mensal);
    }

    /**
     * `chave` é UNIQUE. Sem o guard, o UPDATE que renomeia para `<chave>_LEGADO`
     * estoura violação de unicidade quando essa chave já existe — e como o
     * Postgres aborta a transação inteira no primeiro erro, toda query seguinte
     * do seeder falharia em cascata, não só a do plano em conflito.
     */
    public function testNaoQuebraSeAChaveLegadoJaExistir(): void
    {
        model(PlanModel::class)->where('chave', 'PRATA')->set(['preco_mensal' => 1850.00])->update();

        // Upsert em vez de insert cego: `chave` é UNIQUE de verdade no banco, e
        // o ambiente de teste já tem PRATA_LEGADO como baseline permanente
        // (exigida pelo TenantFactory). Um insert() colidindo com ela falha em
        // silêncio (DBDebug=false), deixando o teste comparar contra o preço da
        // baseline em vez da fixture proposital daqui. Usa o query builder cru
        // (como o próprio PlanSeeder::upsert() faz) em vez do Model: passar
        // update($id, $data) sem a chave 'id' no array deixa o placeholder
        // {id} da regra is_unique[plans.chave,id,{id}] vazio, a validação se
        // autorrejeita comparando a linha com ela mesma, e o update() retorna
        // false em silêncio — outro caso do mesmo padrão de falha silenciosa.
        $db = \Config\Database::connect();
        $legadoExistente = $db->table('plans')->where('chave', 'PRATA_LEGADO')->get()->getRowArray();
        $fixtureLegado = [
            'chave'        => 'PRATA_LEGADO',
            'nome'         => 'Prata legado pré-existente',
            'preco_mensal' => 1234.00,
            'ativo'        => 'f',
        ];

        if ($legadoExistente) {
            $db->table('plans')->where('id', $legadoExistente['id'])->update($fixtureLegado);
        } else {
            $db->table('plans')->insert($fixtureLegado);
        }

        $this->semear();

        // O plano novo precisa ter sido criado apesar do conflito — é a prova
        // de que a transação não abortou.
        $this->assertSame(990.0, (float) $this->plan('PRATA')->preco_mensal);
        $this->assertSame(1690.0, (float) $this->plan('OURO')->preco_mensal);

        // O PRATA_LEGADO pré-existente não foi mexido pelo seeder.
        $this->assertSame(1234.0, (float) $this->plan('PRATA_LEGADO')->preco_mensal);
    }

    /**
     * Planos fora do catálogo comercial atual (legados de antes desta
     * reestruturação, criados fora deste seeder) precisam sair de `ativo`
     * pra parar de aparecer no checkout e na troca de plano — sem apagar a
     * linha nem tocar em assinatura nenhuma que ainda esteja neles.
     */
    public function testPlanosForaDoCatalogoSaoDesativados(): void
    {
        $model = model(PlanModel::class);

        foreach (['START', 'PRO', 'IMOBILIARIA', 'TEST_FREE'] as $chave) {
            $model->insert([
                'chave'        => $chave,
                'nome'         => 'Plano ' . $chave,
                'preco_mensal' => 0.00,
                'ativo'        => true,
            ]);
        }

        $tenant = (new TenantFactory())->create();
        model(SubscriptionModel::class)
            ->where('account_id', $tenant['account']->id)
            ->set(['plan_id' => $this->plan('PRO')->id])
            ->update();

        $this->semear();

        foreach (['START', 'PRO', 'IMOBILIARIA', 'TEST_FREE'] as $chave) {
            $this->assertFalse((bool) $this->plan($chave)->ativo, "{$chave} deveria estar desativado");
        }

        // A assinatura que apontava pro PRO continua íntegra — desativar o
        // plano não mexe em quem já está nele.
        $sub = model(SubscriptionModel::class)->where('account_id', $tenant['account']->id)->first();
        $this->assertSame($this->plan('PRO')->id, $sub->plan_id);
    }
}
