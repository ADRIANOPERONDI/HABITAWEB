<?php

namespace Tests\Feature;

use App\Models\PromotionModel;
use App\Models\PropertyModel;
use App\Services\PropertyService;
use App\Services\RankingService;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Cobre o vencimento da turbinada em tempo de LEITURA.
 *
 * `highlight_level`/`highlight_expires_at` são denormalizações e a limpeza
 * dependia só do cron `promo:cleanup` — que não estava agendado em lugar nenhum.
 * Nenhuma query conferia a data, então destaque vencido continuava valendo. Com
 * turbinada vendida por 7 dias, isso é entregar mais do que foi cobrado.
 *
 * Cobre também a remoção do boost de promoção sobre `score_qualidade`, que
 * estourava a escala 0–100 e, por a nota ser usada como multiplicador na
 * ordenação, multiplicava a relevância em vez de somá-la.
 */
final class HighlightExpiryTest extends HabitawebTestCase
{
    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountId = (int) (new TenantFactory())->create()['account']->id;
    }

    private function makeProperty(array $overrides = []): int
    {
        $model = new PropertyModel();
        $model->insert(array_merge([
            'account_id'      => $this->accountId,
            'tipo_negocio'    => 'VENDA',
            'tipo_imovel'     => 'apartamento',
            'titulo'          => 'Imóvel turbinado',
            'cidade'          => 'São Paulo',
            'bairro'          => 'Centro',
            'preco'           => 500000,
            'status'          => 'ACTIVE',
            'is_destaque'     => false,
            'score_qualidade' => 50,
        ], $overrides));

        return (int) $model->getInsertID();
    }

    private function sponsoredIds(): array
    {
        return array_map(
            static fn ($p) => (int) $p->id,
            (new PropertyService())->getSponsoredPool(50)
        );
    }

    public function testTurbinadaVigenteEntraNoPoolPatrocinado(): void
    {
        $id = $this->makeProperty([
            'highlight_level'      => 1,
            'highlight_expires_at' => date('Y-m-d H:i:s', strtotime('+3 days')),
        ]);

        $this->assertContains($id, $this->sponsoredIds());
    }

    public function testTurbinadaVencidaNaoEntraNoPoolPatrocinado(): void
    {
        $id = $this->makeProperty([
            'highlight_level'      => 1,
            'highlight_expires_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $this->assertNotContains(
            $id,
            $this->sponsoredIds(),
            'Destaque vencido não pode continuar exposto só porque o cron não rodou.'
        );
    }

    public function testTurbinadaSemDataDeFimNaoValeComoVigente(): void
    {
        $id = $this->makeProperty([
            'highlight_level'      => 2,
            'highlight_expires_at' => null,
        ]);

        $this->assertNotContains($id, $this->sponsoredIds());
    }

    public function testTurbinadaVencidaVoltaAoPoolOrganico(): void
    {
        $id = $this->makeProperty([
            'highlight_level'      => 1,
            'highlight_expires_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ]);

        $organicIds = array_map(
            static fn ($p) => (int) $p->id,
            (new PropertyService())->getNonPaidProperties(50)
        );

        $this->assertContains($id, $organicIds);
    }

    public function testScoreDeQualidadeNaoRecebeBoostDePromocao(): void
    {
        $id = $this->makeProperty();

        model(PromotionModel::class)->insert([
            'property_id'   => $id,
            'tipo_promocao' => 'SUPER_DESTAQUE',
            'data_inicio'   => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'data_fim'      => date('Y-m-d H:i:s', strtotime('+7 days')),
            'ativo'         => true,
        ]);

        $property = (new PropertyModel())->find($id);
        $score    = (new RankingService())->calculateScore($property);

        $this->assertLessThanOrEqual(
            100,
            $score,
            'score_qualidade é nota 0-100 e serve de multiplicador na ordenação; promoção não pode inflá-la.'
        );
    }
}
