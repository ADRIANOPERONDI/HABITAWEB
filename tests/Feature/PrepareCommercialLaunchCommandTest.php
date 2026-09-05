<?php

namespace Tests\Feature;

use App\Models\LeadChargeRuleModel;
use App\Models\PlanLaunchRampModel;
use App\Models\PlanModel;
use Tests\Support\HabitawebTestCase;

/**
 * `spark comercial:preparar-lancamento` — condensa em um comando só os três
 * passos manuais do runbook (§13.1): `db:seed MainSeeder` (planos, pacotes,
 * regras de lead) + o `UPDATE` de `plan_launch_ramps` com a janela de
 * validade combinada com o cliente.
 *
 * @internal
 */
final class PrepareCommercialLaunchCommandTest extends HabitawebTestCase
{
    private function runCommand(string $args = ''): void
    {
        ob_start();
        command('comercial:preparar-lancamento ' . $args);
        ob_end_clean();
    }

    public function testSemeiaPlanosERegrasEAplicaJanelaDaRampa(): void
    {
        $this->runCommand('--rampa-valid-from 2026-01-01 --rampa-valid-to 2026-12-31');

        $planos = model(PlanModel::class)->whereIn('chave', ['PRATA', 'OURO', 'DIAMANTE'])->findAll();
        $this->assertCount(3, $planos);

        $regras = model(LeadChargeRuleModel::class)->where('account_id', null)->findAll();
        $this->assertNotEmpty($regras);

        $faixas = model(PlanLaunchRampModel::class)->findAll();
        $this->assertNotEmpty($faixas);
        foreach ($faixas as $faixa) {
            $this->assertSame('2026-01-01', $faixa['valid_from']);
            $this->assertSame('2026-12-31', $faixa['valid_to']);
        }
    }

    public function testRodarDuasVezesNaoDuplicaPlanosNemRegras(): void
    {
        $this->runCommand('--rampa-valid-to 2026-12-31');
        $totalPlanosAntes = model(PlanModel::class)->countAllResults();
        $totalRegrasAntes = model(LeadChargeRuleModel::class)->where('account_id', null)->countAllResults();

        $this->runCommand('--rampa-valid-to 2026-12-31');
        $totalPlanosDepois = model(PlanModel::class)->countAllResults();
        $totalRegrasDepois = model(LeadChargeRuleModel::class)->where('account_id', null)->countAllResults();

        $this->assertSame($totalPlanosAntes, $totalPlanosDepois);
        $this->assertSame($totalRegrasAntes, $totalRegrasDepois);
    }

    public function testDryRunNaoAlteraAJanelaDaRampa(): void
    {
        $db = \Config\Database::connect();
        $db->table('plan_launch_ramps')->update(['valid_from' => '2020-01-01', 'valid_to' => null]);

        $this->runCommand('--dry-run --rampa-valid-to 2099-01-01');

        foreach (model(PlanLaunchRampModel::class)->findAll() as $faixa) {
            $this->assertSame('2020-01-01', $faixa['valid_from']);
            $this->assertNull($faixa['valid_to']);
        }
    }

    public function testValidToAnteriorAValidFromRecusaSemGravarNada(): void
    {
        $db = \Config\Database::connect();
        $db->table('plan_launch_ramps')->update(['valid_from' => '2020-01-01', 'valid_to' => null]);

        $this->runCommand('--rampa-valid-from 2026-06-01 --rampa-valid-to 2026-01-01');

        foreach (model(PlanLaunchRampModel::class)->findAll() as $faixa) {
            $this->assertSame('2020-01-01', $faixa['valid_from'], 'nao pode gravar com valid_to anterior a valid_from');
            $this->assertNull($faixa['valid_to']);
        }
    }
}
