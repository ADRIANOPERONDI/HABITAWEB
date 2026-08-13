<?php

namespace Tests\Feature;

use App\Entities\PlanFeature;
use App\Models\PlanModel;
use App\Models\PropertyModel;
use App\Models\SubscriptionModel;
use App\Services\PlanGate;
use App\Services\PropertyService;
use App\Services\PublicPropertyVisibilityService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre a garantia central da Fase 2: exposição paga é POSIÇÃO dentro do
 * resultado relevante, nunca um jeito de aparecer fora do que o visitante
 * pediu.
 *
 * `testDiamanteCaroNaoAparecePorNenhumCaminhoNumaBuscaBarata` é literalmente o
 * cenário que o cliente descreveu ao pedir a Fase 2: "Apartamento até R$500 mil
 * no Centro" não pode trazer a casa de R$1,5 milhão só porque o dono paga o
 * plano mais caro.
 *
 * `bairro`/`cidade` são gerados únicos por teste (não "Centro"/"São Paulo"
 * fixos): `habitaweb_test` carrega fixtures permanentes de outras suítes
 * (E2E, seeders) que batem por coincidência num nome de bairro genérico —
 * já aconteceu aqui (imóvel de fixture id 444, "Centro"/"São Paulo", criado
 * por outra suíte, contaminou a contagem de um destes testes). Bairro
 * exclusivo do teste elimina a colisão sem precisar limpar a tabela inteira.
 */
final class SponsoredSlotTest extends HabitawebTestCase
{
    private PropertyService $service;
    private string $bairro;
    private string $cidade;

    protected function setUp(): void
    {
        parent::setUp();
        PlanGate::flushMemo();
        PublicPropertyVisibilityService::invalidateCaches();
        cache()->clean();

        $this->service = new PropertyService();

        $suffix = bin2hex(random_bytes(4));
        $this->bairro = "Bairro Slot {$suffix}";
        $this->cidade = "Cidade Slot {$suffix}";
    }

    protected function tearDown(): void
    {
        PublicPropertyVisibilityService::invalidateCaches();
        parent::tearDown();
    }

    private function makePlan(array $overrides = []): int
    {
        $model = model(PlanModel::class);
        $model->insert(array_merge([
            'chave'        => 'SLOT_' . bin2hex(random_bytes(4)),
            'nome'         => 'Plano Slot ' . bin2hex(random_bytes(4)),
            'preco_mensal' => 990.00,
            'ativo'        => true,
        ], $overrides));

        return (int) $model->getInsertID();
    }

    /** Conta com assinatura ACTIVE no plano dado. */
    private function makeAccount(int $planId): int
    {
        $accountId = (int) (new TenantFactory())->create()['account']->id;

        model(SubscriptionModel::class)
            ->where('account_id', $accountId)
            ->set(['plan_id' => $planId, 'status' => 'ACTIVE'])
            ->update();

        PlanGate::forget($accountId);

        return $accountId;
    }

    private function insertProperty(int $accountId, array $overrides = []): int
    {
        $model = new PropertyModel();
        $model->insert(array_merge([
            'account_id'      => $accountId,
            'tipo_negocio'    => 'VENDA',
            'tipo_imovel'     => 'apartamento',
            'titulo'          => 'Imóvel ' . bin2hex(random_bytes(4)),
            'cidade'          => $this->cidade,
            'bairro'          => $this->bairro,
            'preco'           => 400000,
            'status'          => 'ACTIVE',
            'score_qualidade' => 50,
        ], $overrides));

        return (int) $model->getInsertID();
    }

    private function turbinar(int $propertyId): void
    {
        (new PropertyModel())->update($propertyId, [
            'highlight_level'      => 1,
            'highlight_expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
    }

    /** Filtros de busca já escopados ao bairro exclusivo deste teste. */
    private function idsFromMapList(array $extraFilters = [], int $page = 1): array
    {
        $filters = array_merge(['bairro' => $this->bairro, 'cidade' => $this->cidade], $extraFilters);
        $result  = $this->service->searchMapList($filters, 18, $page);

        return array_map(static fn ($p) => (int) $p->id, iterator_to_array($result['properties']));
    }

    // ---- a garantia central --------------------------------------------

    public function testDiamanteCaroNaoAparecePorNenhumCaminhoNumaBuscaBarata(): void
    {
        $diamante = $this->makePlan([
            'exposure_weight' => 20,
            'features'        => [PlanFeature::EXPOSICAO_BUSCA => true],
        ]);
        $contaDiamante = $this->makeAccount($diamante);
        $casaCara = $this->insertProperty($contaDiamante, [
            'titulo' => 'Casa de Luxo', 'preco' => 1500000, 'score_qualidade' => 90,
        ]);
        $this->turbinar($casaCara);

        $contaComum = $this->makeAccount($this->makePlan());
        $apartamentoBarato = $this->insertProperty($contaComum, [
            'titulo' => 'Apto Popular', 'preco' => 480000, 'score_qualidade' => 40,
        ]);

        $ids = $this->idsFromMapList(['max_price' => 500000]);

        $this->assertNotContains(
            $casaCara,
            $ids,
            'A casa de R$1,5mi turbinada pelo Diamante não pode aparecer numa busca até R$500 mil — nem como orgânico, nem como slot patrocinado.'
        );
        $this->assertContains($apartamentoBarato, $ids);
    }

    // ---- ranking orgânico não usa preço de plano ------------------------

    public function testRankingOrganicoIgnoraPrecoDoPlano(): void
    {
        $planBarato = $this->makePlan(['preco_mensal' => 990.00]);
        $planCaro   = $this->makePlan(['preco_mensal' => 2490.00]);

        $contaBarata = $this->makeAccount($planBarato);
        $contaCara   = $this->makeAccount($planCaro);

        // Mesmo score, ordem de criação conhecida: o mais recente vem primeiro
        // se o preço do plano não entra na conta.
        $imovelPlanoBarato = $this->insertProperty($contaBarata, ['score_qualidade' => 50]);
        usleep(1_100_000); // created_at com resolução de segundo — garante ordem
        $imovelPlanoCaro   = $this->insertProperty($contaCara, ['score_qualidade' => 50]);

        $ids = $this->idsFromMapList();
        $posBarato = array_search($imovelPlanoBarato, $ids, true);
        $posCaro   = array_search($imovelPlanoCaro, $ids, true);

        $this->assertNotFalse($posBarato);
        $this->assertNotFalse($posCaro);
        $this->assertLessThan(
            $posBarato,
            $posCaro,
            'O imóvel mais recente devia vir primeiro — se o plano caro furasse a fila, essa ordem se inverteria.'
        );
    }

    // ---- elegibilidade ao slot ------------------------------------------

    public function testTurboSemAFeatureDoPlanoNaoOcupaSlot(): void
    {
        // Prata da proposta: pode comprar turbinada avulsa, mas não tem
        // exposicao.busca — não deve furar fila de busca de outra conta.
        $prata = $this->makePlan(['features' => []]);
        $conta = $this->makeAccount($prata);
        $imovel = $this->insertProperty($conta, ['score_qualidade' => 10]);
        $this->turbinar($imovel);

        // Imóvel organicamente melhor de outra conta, sem turbo.
        $outraConta = $this->makeAccount($this->makePlan());
        $melhorOrganico = $this->insertProperty($outraConta, ['score_qualidade' => 90]);

        $ids = $this->idsFromMapList();

        $this->assertSame(
            $melhorOrganico,
            $ids[0],
            'Turbo sem a feature exposicao.busca não pode saltar para a posição 1 do slot.'
        );
    }

    public function testTurboComAFeatureOcupaOSlotDaPrimeiraPosicao(): void
    {
        $diamante = $this->makePlan([
            'exposure_weight' => 20,
            'features'        => [PlanFeature::EXPOSICAO_BUSCA => true],
        ]);
        $contaPatrocinada = $this->makeAccount($diamante);
        $patrocinado = $this->insertProperty($contaPatrocinada, ['score_qualidade' => 10]);
        $this->turbinar($patrocinado);

        $contaOrganica = $this->makeAccount($this->makePlan());
        $organico = $this->insertProperty($contaOrganica, ['score_qualidade' => 90]);

        $ids = $this->idsFromMapList();

        $this->assertSame($patrocinado, $ids[0], 'O patrocinado elegível ocupa a posição 1.');
        $this->assertContains($organico, $ids, 'O orgânico continua no resultado, só não na posição 1.');
        $this->assertSame(
            1,
            count(array_keys($ids, $patrocinado, true)),
            'O patrocinado não pode aparecer duas vezes na mesma página (posição 1 + posição orgânica).'
        );
    }

    public function testEditorialDestaqueTambemOcupaSlotIndependenteDePlano(): void
    {
        // is_destaque é curadoria da Habitaweb, não depende de feature de plano.
        $conta = $this->makeAccount($this->makePlan(['features' => []]));
        $curado = $this->insertProperty($conta, ['score_qualidade' => 10, 'is_destaque' => true]);

        $outraConta = $this->makeAccount($this->makePlan());
        $organico = $this->insertProperty($outraConta, ['score_qualidade' => 90]);

        $ids = $this->idsFromMapList();

        $this->assertSame($curado, $ids[0]);
    }

    public function testSemNinguemElegivelResultadoFicaPuramenteOrganico(): void
    {
        $conta = $this->makeAccount($this->makePlan());
        $a = $this->insertProperty($conta, ['score_qualidade' => 90]);
        $b = $this->insertProperty($conta, ['score_qualidade' => 10]);

        $ids = $this->idsFromMapList();

        $this->assertSame([$a, $b], $ids, 'Sem elegível, o slot colapsa em orgânico — sem buracos, sem reordenação.');
    }

    // ---- página e ordenação explícita não recebem slot -------------------

    public function testPaginaDoisNaoRecebeSlot(): void
    {
        $diamante = $this->makePlan([
            'exposure_weight' => 20,
            'features'        => [PlanFeature::EXPOSICAO_BUSCA => true],
        ]);
        $contaPatrocinada = $this->makeAccount($diamante);
        $patrocinado = $this->insertProperty($contaPatrocinada, ['score_qualidade' => 5]);
        $this->turbinar($patrocinado);

        $outraConta = $this->makeAccount($this->makePlan());
        for ($i = 0; $i < 20; $i++) {
            $this->insertProperty($outraConta, ['score_qualidade' => 50 + $i]);
        }

        $idsPagina2 = $this->idsFromMapList([], 2);

        // O patrocinado tem o pior score de todos — só apareceria na página 2
        // organicamente, nunca inserido num slot (slots são só página 1).
        $this->assertContains($patrocinado, $idsPagina2);
        // E não deve estar duplicado/inserido artificialmente no topo da página 2.
        $this->assertSame(
            1,
            count(array_keys($idsPagina2, $patrocinado, true))
        );
    }

    public function testOrdenacaoPorPrecoNaoRecebeSlot(): void
    {
        $diamante = $this->makePlan([
            'exposure_weight' => 20,
            'features'        => [PlanFeature::EXPOSICAO_BUSCA => true],
        ]);
        $contaPatrocinada = $this->makeAccount($diamante);
        $patrocinado = $this->insertProperty($contaPatrocinada, ['preco' => 900000, 'score_qualidade' => 99]);
        $this->turbinar($patrocinado);

        $contaBarata = $this->makeAccount($this->makePlan());
        $barato = $this->insertProperty($contaBarata, ['preco' => 100000, 'score_qualidade' => 1]);

        $result = $this->service->searchMapList(
            ['bairro' => $this->bairro, 'cidade' => $this->cidade, 'sort' => 'price_asc'],
            18,
            1
        );
        $properties = iterator_to_array($result['properties']);

        $precos = array_map(static fn ($p) => (float) $p->preco, $properties);
        $ordenado = $precos;
        sort($ordenado);

        $this->assertSame(
            $ordenado,
            $precos,
            'sort=price_asc é escolha explícita do usuário — preço tem que vir estritamente crescente, sem slot furando a ordem.'
        );
        $this->assertSame((int) $properties[0]->id, $barato);
    }

    // ---- getFeaturedProperties (prateleira da home) ----------------------

    public function testPrateleiraDaHomeSoTraTazElegiveis(): void
    {
        $diamante = $this->makePlan([
            'exposure_weight' => 20,
            'features'        => [PlanFeature::EXPOSICAO_BUSCA => true],
        ]);
        $contaElegivel = $this->makeAccount($diamante);
        $elegivel = $this->insertProperty($contaElegivel, ['score_qualidade' => 10]);
        $this->turbinar($elegivel);

        $contaComum = $this->makeAccount($this->makePlan());
        $naoElegivel = $this->insertProperty($contaComum, ['score_qualidade' => 99]);

        $ids = array_map(static fn ($p) => (int) $p->id, $this->service->getFeaturedProperties(10));

        $this->assertContains($elegivel, $ids);
        $this->assertNotContains(
            $naoElegivel,
            $ids,
            'Sem turbo+feature nem curadoria editorial, o imóvel não entra na prateleira — mesmo com score melhor.'
        );
    }

    public function testPrateleiraDaHomeVaziaQuandoNaoHaElegivel(): void
    {
        $conta = $this->makeAccount($this->makePlan());
        $this->insertProperty($conta, ['score_qualidade' => 99]);

        $this->assertSame([], $this->service->getFeaturedProperties(10));
    }
}
