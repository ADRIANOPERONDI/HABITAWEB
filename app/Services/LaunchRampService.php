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
     */
    public function percentFor(?Subscription $subscription, ?string $onDate = null): int
    {
        $mesVida = $this->monthsAlive($subscription, $onDate);
        if ($mesVida === null) {
            return 100;
        }

        $faixa = $this->rampModel->forMonth($mesVida, $onDate);

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

        $atual = $this->rampModel->forMonth($mesVida, $onDate);
        if ($atual === null || $atual['mes_ate'] === null) {
            return null;
        }

        $proxima = $this->rampModel->forMonth((int) $atual['mes_ate'] + 1, $onDate);
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
