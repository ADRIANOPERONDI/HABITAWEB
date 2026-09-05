<?php

namespace Tests\Feature;

use App\Database\Seeds\LeadChargeRuleSeeder;
use App\Models\LeadChargeRuleModel;
use Tests\Support\HabitawebTestCase;

/**
 * Sem este seeder, `lead_charge_rules` fica vazia e
 * `LeadChargeRuleModel::resolveFor()` nunca acha regra nenhuma — a cobrança
 * por lead recebido, a única receita do semestre de lançamento, não liga
 * sozinha.
 *
 * @internal
 */
final class LeadChargeRuleSeederTest extends HabitawebTestCase
{
    private function semear(): void
    {
        $this->seed(LeadChargeRuleSeeder::class);
    }

    /** @return \App\Entities\LeadChargeRule[] indexadas por tipo_negocio */
    private function regrasPadrao(): array
    {
        $regras = model(LeadChargeRuleModel::class)
            ->where('account_id', null)
            ->where('provider_code', null)
            ->findAll();

        $porTipo = [];

        foreach ($regras as $regra) {
            $porTipo[$regra->tipo_negocio] = $regra;
        }

        return $porTipo;
    }

    public function testCriaAsTresRegrasPadrao(): void
    {
        $this->semear();

        $porTipo = $this->regrasPadrao();

        $this->assertCount(3, $porTipo);
        $this->assertSame(80.0, (float) $porTipo['VENDA']->value);
        $this->assertSame(40.0, (float) $porTipo['ALUGUEL']->value);
        $this->assertSame(40.0, (float) $porTipo['TEMPORADA']->value);

        foreach ($porTipo as $regra) {
            $this->assertSame(LeadChargeRuleModel::MODEL_FIXED, $regra->model);
            $this->assertTrue($regra->is_active);
            $this->assertNotNull($regra->valid_from, 'sem valid_from a regra nunca é resolvida por resolveFor()');
        }
    }

    /**
     * `db:seed MainSeeder` pode rodar mais de uma vez num deploy (reexecução
     * do pipeline, por exemplo) — não pode duplicar regra a cada rodada, já
     * que `resolveFor()` escolhe a mais específica entre TODAS as ativas.
     */
    public function testRodarDuasVezesNaoDuplica(): void
    {
        $this->semear();
        $this->semear();

        $this->assertCount(3, $this->regrasPadrao());
    }
}
