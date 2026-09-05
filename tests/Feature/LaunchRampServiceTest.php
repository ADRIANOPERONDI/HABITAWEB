<?php

namespace Tests\Feature;

use App\Entities\Subscription;
use App\Models\PlanLaunchRampModel;
use App\Models\PlanModel;
use App\Services\LaunchRampService;
use Tests\Support\HabitawebTestCase;

/**
 * `LaunchRampService` é o único ponto que decide quanto uma assinatura deve
 * pagar agora. A garantia mais importante aqui é a de segurança: uma
 * assinatura sem `ramp_started_at` (toda conta hoje, e qualquer fluxo que
 * este serviço ainda não toca) tem que se comportar EXATAMENTE como antes
 * da rampa existir — 100%, preço cheio, nenhuma surpresa.
 */
final class LaunchRampServiceTest extends HabitawebTestCase
{
    /**
     * As três faixas de produção, mas com `valid_from` fixo no passado — a
     * migration semeia `valid_from = hoje` (correto: a campanha vale a
     * partir do deploy), então testar com datas absolutas de 2026 quebraria
     * sempre que a suíte rodasse num dia diferente do deploy.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $db = \Config\Database::connect();
        $db->table('plan_launch_ramps')->truncate();

        model(PlanLaunchRampModel::class)->insertBatch([
            ['mes_de' => 1, 'mes_ate' => 6, 'percentual' => 0, 'is_active' => true, 'valid_from' => '2020-01-01'],
            ['mes_de' => 7, 'mes_ate' => 12, 'percentual' => 50, 'is_active' => true, 'valid_from' => '2020-01-01'],
            ['mes_de' => 13, 'mes_ate' => null, 'percentual' => 100, 'is_active' => true, 'valid_from' => '2020-01-01'],
        ]);
    }

    private function plan(float $precoMensal = 990.00): int
    {
        return (int) model(PlanModel::class)->insert([
            'chave' => 'RAMPA_' . bin2hex(random_bytes(4)),
            'nome' => 'Plano Rampa ' . bin2hex(random_bytes(4)),
            'preco_mensal' => $precoMensal,
            'ativo' => true,
        ], true);
    }

    public function testAssinaturaSemRampStartedAtCobraCemPorCentoIndependenteDeQualquerCoisa(): void
    {
        $service = new LaunchRampService();
        $semRampa = new Subscription(['ramp_started_at' => null]);

        $this->assertSame(100, $service->percentFor($semRampa));
        $this->assertNull($service->monthsAlive($semRampa));
        $this->assertNull($service->nextTransition($semRampa));
    }

    public function testAssinaturaNulaTambemCobraCemPorCento(): void
    {
        $service = new LaunchRampService();

        $this->assertSame(100, $service->percentFor(null));
    }

    public function testMonthsAliveContaAPartirDoRampStartedAt(): void
    {
        $service = new LaunchRampService();
        $sub = new Subscription(['ramp_started_at' => '2026-01-15']);

        $this->assertSame(1, $service->monthsAlive($sub, '2026-01-15'));
        $this->assertSame(1, $service->monthsAlive($sub, '2026-02-14'));
        $this->assertSame(2, $service->monthsAlive($sub, '2026-02-15'));
        $this->assertSame(7, $service->monthsAlive($sub, '2026-07-15'));
        $this->assertSame(13, $service->monthsAlive($sub, '2027-01-15'));
    }

    public function testPercentForSeguiOSeedDeProducaoNasTresFaixas(): void
    {
        $service = new LaunchRampService();
        $sub = new Subscription(['ramp_started_at' => '2026-01-01']);

        $this->assertSame(0, $service->percentFor($sub, '2026-01-01'), 'mes 1: gratis');
        $this->assertSame(0, $service->percentFor($sub, '2026-06-01'), 'mes 6: ainda gratis');
        $this->assertSame(50, $service->percentFor($sub, '2026-07-01'), 'mes 7: metade');
        $this->assertSame(50, $service->percentFor($sub, '2026-12-01'), 'mes 12: ainda metade');
        $this->assertSame(100, $service->percentFor($sub, '2027-01-01'), 'mes 13: cheio');
        $this->assertSame(100, $service->percentFor($sub, '2030-01-01'), 'muito depois: continua cheio (faixa aberta)');
    }

    public function testAmountForAplicaOPercentualSobreOPrecoDoCiclo(): void
    {
        $planId = $this->plan(990.00);
        $plan = model(PlanModel::class)->find($planId);
        $service = new LaunchRampService();

        $mes1 = new Subscription(['ramp_started_at' => '2026-01-01']);
        $mes7 = new Subscription(['ramp_started_at' => '2026-01-01']);
        $mes13 = new Subscription(['ramp_started_at' => '2026-01-01']);
        $semRampa = new Subscription(['ramp_started_at' => null]);

        $this->assertSame(0.0, $service->amountFor($plan, 'MONTHLY', $mes1, '2026-01-15'));
        $this->assertSame(495.0, $service->amountFor($plan, 'MONTHLY', $mes7, '2026-07-15'));
        $this->assertSame(990.0, $service->amountFor($plan, 'MONTHLY', $mes13, '2027-01-15'));
        $this->assertSame(990.0, $service->amountFor($plan, 'MONTHLY', $semRampa, '2026-01-15'), 'sem rampa, cobra o preco cheio do plano igual antes');
    }

    public function testNextTransitionPreveDataEPercentuais(): void
    {
        $service = new LaunchRampService();
        $sub = new Subscription(['ramp_started_at' => '2026-01-15']);

        $proxima = $service->nextTransition($sub, '2026-01-20');

        $this->assertSame('2026-07-15', $proxima['date']);
        $this->assertSame(0, $proxima['from_percent']);
        $this->assertSame(50, $proxima['to_percent']);
    }

    public function testNextTransitionENulaNaFaixaAberta(): void
    {
        $service = new LaunchRampService();
        $sub = new Subscription(['ramp_started_at' => '2020-01-01']);

        $this->assertNull($service->nextTransition($sub, '2026-01-01'), 'ja passou do mes 13, faixa aberta nao tem proxima transicao');
    }

    /**
     * D1/P6: todo cadastro novo pelo checkout entra na rampa, mas só no
     * ciclo mensal — aplicar o desconto de rampa a um plano anual criaria
     * pró-rata sobre um valor de 12 meses pago de uma vez, ou "anual a R$ 0".
     */
    public function testDataDeAdesaoParaNovoCadastroSoNoMensal(): void
    {
        $service = new LaunchRampService();

        $this->assertSame(date('Y-m-d'), $service->enrollmentDateForNewSignup('MONTHLY'));
        $this->assertNull($service->enrollmentDateForNewSignup('QUARTERLY'));
        $this->assertNull($service->enrollmentDateForNewSignup('SEMIANNUALLY'));
        $this->assertNull($service->enrollmentDateForNewSignup('YEARLY'));
    }

    /**
     * Sem faixa configurada pro mês 1, marcar `ramp_started_at = hoje`
     * gravaria uma data sem nenhum efeito prático (percentFor() cairia no
     * fallback de 100% de qualquer jeito) — melhor nem entrar na rampa.
     */
    public function testDataDeAdesaoENulaSemFaixaConfiguradaParaOMesUm(): void
    {
        $db = \Config\Database::connect();
        $db->table('plan_launch_ramps')->truncate();

        $service = new LaunchRampService();

        $this->assertNull($service->enrollmentDateForNewSignup('MONTHLY'));
    }
}
