<?php

namespace App\Services;

use App\Entities\Subscription;
use App\Models\PlanLaunchRampModel;

/**
 * Rampa de lançamento por coorte (Fase 6): único ponto que decide "quanto
 * esta assinatura deveria pagar agora", cruzando o preço do plano/ciclo com
 * a faixa vigente para o mês de vida da conta (contado a partir de
 * `subscriptions.ramp_started_at`, não do calendário — por isso não é
 * modelado como cupom).
 *
 * `ramp_started_at` nulo = a assinatura não participa da rampa. Este é o
 * default seguro: `percentFor()`/`amountFor()` para uma assinatura sem rampa
 * devolvem exatamente o comportamento de hoje (100%, preço cheio) — nenhuma
 * conta existente é afetada por este serviço até alguém explicitamente
 * marcar `ramp_started_at` nela.
 *
 * Carência negociada (revisão da área pública, item 11): a condição deixou de
 * ser dada a todo cadastro e passou a ser concedida pelo administrador a quem
 * fechou negócio, com dois prazos próprios da conta
 * (`isencao_total_meses`: sem mensalidade e sem leads;
 * `isencao_mensalidade_meses`: depois, sem mensalidade mas com leads). Com os
 * prazos preenchidos, eles mandam; sem eles, vale a regra antiga das faixas
 * globais (`plan_launch_ramps`), só para o que foi gravado antes.
 */
class LaunchRampService
{
    protected PlanLaunchRampModel $rampModel;
    protected PaymentService $paymentService;

    public function __construct(?PaymentService $paymentService = null)
    {
        $this->rampModel      = model(PlanLaunchRampModel::class);
        $this->paymentService = $paymentService ?? new PaymentService();
    }

    /**
     * Data de adesão à rampa para um cadastro NOVO pelo checkout (D1), ou
     * null se este cadastro não deve entrar nela.
     *
     * Só MONTHLY entra (P6): a rampa desconta um percentual do preço do
     * CICLO, e aplicar isso a um plano anual criaria pró-rata sobre um valor
     * de 12 meses pago de uma vez — ou pior, "anual a R$ 0" no primeiro
     * semestre, o que a proposta comercial nunca previu. Cadastro anual paga
     * as 10 mensalidades cheias de sempre; o benefício dele é a exposição
     * extra (turbo bônus), não desconto de rampa.
     *
     * Depende de existir faixa configurada para o mês 1 — sem isso, marcar
     * `ramp_started_at = hoje` só faria a conta entrar numa rampa vazia sem
     * nunca sair dela (percentFor() cai no fallback de 100% de qualquer
     * jeito, mas a data ficaria gravada sem nenhum propósito).
     */
    public function enrollmentDateForNewSignup(string $billingCycle): ?string
    {
        // Cadastro pelo site nunca entra na carência: ela é condição de
        // negociação, concedida pelo administrador (grantNegotiatedTerms em
        // PaymentService). Quem assina sozinho paga o plano desde o início.
        return null;
    }

    /** A assinatura tem carência negociada (prazos próprios da conta)? */
    public function hasNegotiatedTerms(?Subscription $subscription): bool
    {
        return $subscription !== null
            && ! empty($subscription->ramp_started_at)
            && $subscription->isencao_total_meses !== null;
    }

    /**
     * Leads recebidos nesta data ficam sem cobrança? Só no período de isenção
     * total de uma carência negociada.
     */
    public function leadsExemptOn(?Subscription $subscription, ?string $onDate = null): bool
    {
        if (! $this->hasNegotiatedTerms($subscription)) {
            return false;
        }

        return $this->monthsAlive($subscription, $onDate) <= (int) $subscription->isencao_total_meses;
    }

    /**
     * Datas da carência negociada, para o painel: quando os leads passam a
     * ser cobrados e quando a mensalidade passa a ser cobrada. Null sem
     * carência negociada.
     *
     * @return array{inicio: string, leads_a_partir: string, mensalidade_a_partir: string}|null
     */
    public function negotiatedSchedule(?Subscription $subscription): ?array
    {
        if (! $this->hasNegotiatedTerms($subscription)) {
            return null;
        }

        $inicio = new \DateTimeImmutable((string) $subscription->ramp_started_at);
        $total  = (int) $subscription->isencao_total_meses;
        $mensal = (int) ($subscription->isencao_mensalidade_meses ?? 0);

        return [
            'inicio'               => $inicio->format('Y-m-d'),
            'leads_a_partir'       => $inicio->modify("+{$total} months")->format('Y-m-d'),
            'mensalidade_a_partir' => $inicio->modify('+' . ($total + $mensal) . ' months')->format('Y-m-d'),
        ];
    }

    /** Meses, a partir do início, em que a mensalidade fica isenta. */
    private function negotiatedFreeMonths(Subscription $subscription): int
    {
        return (int) $subscription->isencao_total_meses + (int) ($subscription->isencao_mensalidade_meses ?? 0);
    }

    /**
     * Mês de vida da conta (1-indexado) na data de referência, ou null se a
     * assinatura não participa da rampa.
     */
    public function monthsAlive(?Subscription $subscription, ?string $onDate = null): ?int
    {
        $rampStartedAt = $subscription->ramp_started_at ?? null;
        if (empty($rampStartedAt)) {
            return null;
        }

        $start = new \DateTimeImmutable((string) $rampStartedAt);
        $ref   = new \DateTimeImmutable($onDate ?? 'today');

        if ($ref < $start) {
            return 1;
        }

        $diff = $start->diff($ref);

        return ($diff->y * 12 + $diff->m) + 1;
    }

    /**
     * Percentual do preço do ciclo que deve ser cobrado agora. 100 (preço
     * cheio) para assinatura fora da rampa ou sem faixa configurada que
     * cubra o mês de vida — nunca um default generoso.
     *
     * `valid_from`/`valid_to` são checados na data de ADESÃO da conta
     * (`ramp_started_at`), não em `$onDate` — essa janela existe pra limitar
     * quem consegue ENTRAR na promoção (é o que `enrollmentDateForNewSignup`
     * verifica, contra hoje), não pra cortar o benefício de quem já está no
     * meio da própria rampa. Sem essa distinção, uma conta que aderiu dentro
     * do prazo perderia o desconto restante assim que o calendário da
     * promoção fechasse, mesmo com meses de rampa ainda por vir — o
     * combinado com o cliente é "quem entrar até a data X leva os 6 meses
     * inteiros", não "os 6 meses valem só até a data X".
     */
    public function percentFor(?Subscription $subscription, ?string $onDate = null): int
    {
        $mesVida = $this->monthsAlive($subscription, $onDate);
        if ($mesVida === null) {
            return 100;
        }

        if ($this->hasNegotiatedTerms($subscription)) {
            return $mesVida <= $this->negotiatedFreeMonths($subscription) ? 0 : 100;
        }

        $faixa = $this->rampModel->forMonth($mesVida, $subscription->ramp_started_at);

        return $faixa['percentual'] ?? 100;
    }

    /**
     * Valor a cobrar para este plano/ciclo, com o desconto de rampa
     * aplicado. `PaymentService::getPlanAmountForBillingCycle` fica atrás
     * deste método — ele só resolve o preço "de tabela" do ciclo; quem
     * decide o que efetivamente se cobra é este método.
     */
    public function amountFor($plan, string $billingCycle, ?Subscription $subscription, ?string $onDate = null): float
    {
        $base    = $this->paymentService->getPlanAmountForBillingCycle($plan, $billingCycle);
        $percent = $this->percentFor($subscription, $onDate);

        return round($base * $percent / 100, 2);
    }

    /**
     * Próxima transição de faixa (data + percentuais), ou null se a
     * assinatura não participa da rampa ou já está na faixa aberta (sem
     * `mes_ate`, ex.: 13+). Usado pelo `--dry-run` do comando de aplicação
     * para prever caixa.
     */
    public function nextTransition(?Subscription $subscription, ?string $onDate = null): ?array
    {
        $mesVida = $this->monthsAlive($subscription, $onDate);
        if ($mesVida === null) {
            return null;
        }

        if ($this->hasNegotiatedTerms($subscription)) {
            $mesesIsentos = $this->negotiatedFreeMonths($subscription);
            if ($mesesIsentos <= 0 || $mesVida > $mesesIsentos) {
                return null;
            }

            return [
                'date'         => (new \DateTimeImmutable((string) $subscription->ramp_started_at))->modify("+{$mesesIsentos} months")->format('Y-m-d'),
                'from_percent' => 0,
                'to_percent'   => 100,
            ];
        }

        // Mesma razão de percentFor(): valid_from/valid_to são da data de
        // adesão da conta, não de $onDate.
        $atual = $this->rampModel->forMonth($mesVida, $subscription->ramp_started_at);
        if ($atual === null || $atual['mes_ate'] === null) {
            return null;
        }

        $proxima = $this->rampModel->forMonth((int) $atual['mes_ate'] + 1, $subscription->ramp_started_at);
        if ($proxima === null) {
            return null;
        }

        $rampStartedAt  = new \DateTimeImmutable((string) $subscription->ramp_started_at);
        $dataTransicao  = $rampStartedAt->modify('+' . $atual['mes_ate'] . ' months');

        return [
            'date'         => $dataTransicao->format('Y-m-d'),
            'from_percent' => (int) $atual['percentual'],
            'to_percent'   => (int) $proxima['percentual'],
        ];
    }
}
