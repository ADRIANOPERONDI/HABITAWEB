<?php

namespace Tests\Feature;

use App\Models\PlanLaunchRampModel;
use Tests\Support\HabitawebTestCase;

/**
 * `PlanLaunchRampModel` resolve a faixa vigente por mês de vida da conta —
 * a política é dado (`plan_launch_ramps`), não `if` no código. As três
 * faixas do PlanSeeder (1-6=0%, 7-12=50%, 13+=100%) já vêm da migration;
 * este teste prova a resolução em si com faixas isoladas, sem depender do
 * seed de produção continuar exatamente igual no futuro.
 */
final class PlanLaunchRampModelTest extends HabitawebTestCase
{
    private function seedFaixasIsoladas(): void
    {
        $db = \Config\Database::connect();
        $db->table('plan_launch_ramps')->truncate();

        model(PlanLaunchRampModel::class)->insertBatch([
            ['mes_de' => 1, 'mes_ate' => 6, 'percentual' => 0, 'is_active' => true, 'valid_from' => '2020-01-01'],
            ['mes_de' => 7, 'mes_ate' => 12, 'percentual' => 50, 'is_active' => true, 'valid_from' => '2020-01-01'],
            ['mes_de' => 13, 'mes_ate' => null, 'percentual' => 100, 'is_active' => true, 'valid_from' => '2020-01-01'],
        ]);
    }

    public function testForMonthResolveAsTresFaixas(): void
    {
        $this->seedFaixasIsoladas();
        $model = model(PlanLaunchRampModel::class);

        $this->assertSame(0, $model->forMonth(1)['percentual']);
        $this->assertSame(0, $model->forMonth(6)['percentual']);
        $this->assertSame(50, $model->forMonth(7)['percentual']);
        $this->assertSame(50, $model->forMonth(12)['percentual']);
        $this->assertSame(100, $model->forMonth(13)['percentual']);
        $this->assertSame(100, $model->forMonth(999)['percentual'], 'faixa aberta (mes_ate nulo) cobre qualquer mes daí em diante');
    }

    public function testForMonthForaDeQualquerFaixaDevolveNull(): void
    {
        $db = \Config\Database::connect();
        $db->table('plan_launch_ramps')->truncate();

        model(PlanLaunchRampModel::class)->insert([
            'mes_de' => 1, 'mes_ate' => 3, 'percentual' => 0, 'is_active' => true, 'valid_from' => '2020-01-01',
        ]);

        $this->assertNull(model(PlanLaunchRampModel::class)->forMonth(4), 'sem faixa que cubra o mes 4, resolucao devolve null (quem chama cobra cheio)');
    }

    public function testFaixaInativaEIgnorada(): void
    {
        $db = \Config\Database::connect();
        $db->table('plan_launch_ramps')->truncate();

        model(PlanLaunchRampModel::class)->insert([
            'mes_de' => 1, 'mes_ate' => 6, 'percentual' => 0, 'is_active' => false, 'valid_from' => '2020-01-01',
        ]);

        $this->assertNull(model(PlanLaunchRampModel::class)->forMonth(1));
    }

    public function testFaixaComValidToNoPassadoEIgnorada(): void
    {
        $db = \Config\Database::connect();
        $db->table('plan_launch_ramps')->truncate();

        model(PlanLaunchRampModel::class)->insert([
            'mes_de' => 1, 'mes_ate' => 6, 'percentual' => 0, 'is_active' => true,
            'valid_from' => '2020-01-01', 'valid_to' => '2020-06-30',
        ]);

        $this->assertNull(model(PlanLaunchRampModel::class)->forMonth(1, '2026-01-01'));
        $this->assertSame(0, model(PlanLaunchRampModel::class)->forMonth(1, '2020-03-01')['percentual']);
    }

    public function testNextDevolveAProximaFaixaOuNullSeAAtualEAberta(): void
    {
        $this->seedFaixasIsoladas();
        $model = model(PlanLaunchRampModel::class);

        $this->assertSame(50, $model->next(3)['percentual'], 'mes 3 esta na faixa 1-6; a proxima e 7-12 (50%)');
        $this->assertSame(100, $model->next(9)['percentual'], 'mes 9 esta na faixa 7-12; a proxima e 13+ (100%)');
        $this->assertNull($model->next(15), 'mes 15 esta na faixa aberta 13+; nao ha proxima transicao');
    }
}
