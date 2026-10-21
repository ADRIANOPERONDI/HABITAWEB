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

    /**
     * Decisão do cliente: a promoção tem prazo pra quem ENTRA ("quem entrar
     * até dezembro de 2026 leva os 6 meses"), não um prazo que corta o
     * benefício de quem já aderiu. `valid_from`/`valid_to` das faixas
     * precisam ser checados contra `ramp_started_at` (adesão), não contra
     * `$onDate` (hoje) — senão uma conta que aderiu em novembro/2026 (dentro
     * do prazo) perderia o desconto assim que o calendário virasse 2027,
     * mesmo com meses de rampa ainda por vir.
     */
    public function testValidToNaoCortaQuemJaAderiuDentroDoPrazo(): void
    {
        $db = \Config\Database::connect();
        $db->table('plan_launch_ramps')->truncate();
        model(PlanLaunchRampModel::class)->insertBatch([
            ['mes_de' => 1, 'mes_ate' => 6, 'percentual' => 0, 'is_active' => true, 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31'],
            ['mes_de' => 7, 'mes_ate' => 12, 'percentual' => 50, 'is_active' => true, 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31'],
            ['mes_de' => 13, 'mes_ate' => null, 'percentual' => 100, 'is_active' => true, 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31'],
        ]);

        $service = new LaunchRampService();
        // Aderiu em novembro/2026 — dentro do prazo da promoção.
        $sub = new Subscription(['ramp_started_at' => '2026-11-01']);

        // Em fevereiro/2027 (depois do valid_to de todas as faixas), a
        // conta está no mes de vida 4 — ainda deveria estar na faixa
        // gratuita (mes 1-6), não cair pro fallback de 100%.
        $this->assertSame(4, $service->monthsAlive($sub, '2027-02-01'));
        $this->assertSame(0, $service->percentFor($sub, '2027-02-01'), 'promocao fechou pra quem NAO aderiu, nao pra quem ja estava dentro dela');

        $proxima = $service->nextTransition($sub, '2027-02-01');
        $this->assertNotNull($proxima, 'ainda deveria prever a transicao pro mes 7 (maio/2027)');
        $this->assertSame('2027-05-01', $proxima['date']);
        $this->assertSame(50, $proxima['to_percent']);
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
     * Cadastro pelo site não entra mais na carência, em nenhum ciclo: ela é
     * condição de negociação concedida pelo administrador (item 11 da
     * revisão). Antes todo cadastro mensal entrava sozinho (D1).
     */
    public function testCadastroPeloSiteNuncaEntraNaCarencia(): void
    {
        $service = new LaunchRampService();

        foreach (['MONTHLY', 'QUARTERLY', 'SEMIANNUALLY', 'YEARLY'] as $ciclo) {
            $this->assertNull($service->enrollmentDateForNewSignup($ciclo), $ciclo);
        }
    }

    private function negociada(string $inicio, int $total = 6, int $soLeads = 6): Subscription
    {
        return new Subscription([
            'ramp_started_at'           => $inicio,
            'isencao_total_meses'       => $total,
            'isencao_mensalidade_meses' => $soLeads,
        ]);
    }

    /** 6 + 6: mensalidade zero por 12 meses, cheia depois — sem a faixa de 50%. */
    public function testCarenciaNegociadaIsentaMensalidadePelosDoisPrazos(): void
    {
        $service = new LaunchRampService();
        $sub     = $this->negociada('2026-01-10');

        $this->assertSame(0, $service->percentFor($sub, '2026-01-10'), 'mês 1');
        $this->assertSame(0, $service->percentFor($sub, '2026-08-01'), 'mês 7: só leads, mensalidade ainda isenta');
        $this->assertSame(0, $service->percentFor($sub, '2027-01-09'), 'mês 12');
        $this->assertSame(100, $service->percentFor($sub, '2027-01-10'), 'mês 13: plano cheio');
    }

    public function testLeadsSoIsentosNoPrazoDeIsencaoTotal(): void
    {
        $service = new LaunchRampService();
        $sub     = $this->negociada('2026-01-10');

        $this->assertTrue($service->leadsExemptOn($sub, '2026-07-09'), 'último dia do mês 6');
        $this->assertFalse($service->leadsExemptOn($sub, '2026-07-10'), 'mês 7: leads cobrados');
        $this->assertFalse($service->leadsExemptOn(new Subscription(['ramp_started_at' => '2026-01-10']), '2026-02-01'), 'sem prazos negociados, leads seguem a regra normal');
        $this->assertFalse($service->leadsExemptOn(new Subscription(['ramp_started_at' => null]), '2026-02-01'));
    }

    public function testPrazosNegociadosLivresEAgendaParaOPainel(): void
    {
        $service = new LaunchRampService();
        $sub     = $this->negociada('2026-03-01', 3, 9);

        $this->assertSame(
            ['inicio' => '2026-03-01', 'leads_a_partir' => '2026-06-01', 'mensalidade_a_partir' => '2027-03-01'],
            $service->negotiatedSchedule($sub)
        );
        $this->assertSame(
            ['date' => '2027-03-01', 'from_percent' => 0, 'to_percent' => 100],
            $service->nextTransition($sub, '2026-05-01')
        );
        $this->assertNull($service->nextTransition($sub, '2027-03-02'), 'já no plano cheio');
        $this->assertNull($service->negotiatedSchedule(new Subscription(['ramp_started_at' => '2026-03-01'])));
    }

    /** Prazo zero (negociação sem carência) cobra cheio desde o início. */
    public function testCarenciaZeradaCobraCheio(): void
    {
        $service = new LaunchRampService();
        $sub     = $this->negociada('2026-03-01', 0, 0);

        $this->assertSame(100, $service->percentFor($sub, '2026-03-01'));
        $this->assertFalse($service->leadsExemptOn($sub, '2026-03-01'));
        $this->assertNull($service->nextTransition($sub, '2026-03-01'));
    }
}
