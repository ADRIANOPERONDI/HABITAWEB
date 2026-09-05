<?php

namespace App\Services;

use App\Entities\Plan;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use CodeIgniter\Config\Factories;

/**
 * Ponto único para responder "esta conta tem direito a X?".
 *
 * Sem isto, cada consumidor (dashboard, busca, vitrine, página do parceiro)
 * repetiria o mesmo join conta → assinatura ativa → plano, e a regra de qual
 * status conta como vigente divergiria entre eles com o tempo.
 *
 * Conta sem assinatura vigente não tem feature nenhuma — nunca herda o plano
 * anterior nem cai num default generoso.
 */
class PlanGate
{
    /** Status que dão direito ao plano. Cancelado, vencido e pendente não dão. */
    public const STATUS_VIGENTES = ['ACTIVE', 'TRIAL'];

    private const CACHE_TTL = 300;

    /** Memória do processo, para não repetir a query dentro da mesma requisição. */
    private static array $memo = [];

    /**
     * Plano vigente da conta, ou null.
     */
    public static function for(int $accountId): ?Plan
    {
        if ($accountId <= 0) {
            return null;
        }

        if (array_key_exists($accountId, self::$memo)) {
            return self::$memo[$accountId];
        }

        $cacheKey = "plan_gate_{$accountId}";
        $planId   = cache()->get($cacheKey);

        if ($planId === null) {
            $subscription = Factories::models(SubscriptionModel::class)
                ->where('account_id', $accountId)
                ->whereIn('status', self::STATUS_VIGENTES)
                ->orderBy('id', 'DESC')
                ->first();

            // 0 é o marcador de "não tem plano". Guardado em cache igual ao
            // caso positivo: sem isso, conta sem assinatura consultaria o banco
            // a cada request, que é justamente o tráfego do visitante anônimo
            // batendo em página de parceiro.
            $planId = $subscription ? (int) $subscription->plan_id : 0;
            cache()->save($cacheKey, $planId, self::CACHE_TTL);
        }

        $plan = $planId > 0
            ? Factories::models(PlanModel::class)->find($planId)
            : null;

        return self::$memo[$accountId] = $plan;
    }

    public static function has(int $accountId, string $feature): bool
    {
        return self::for($accountId)?->has($feature) ?? false;
    }

    /**
     * Invalida a conta. Chamado pelos callbacks de SubscriptionModel — troca de
     * plano, cancelamento e ativação precisam refletir na hora, não em 5 min.
     */
    public static function forget(int $accountId): void
    {
        unset(self::$memo[$accountId]);
        cache()->delete("plan_gate_{$accountId}");
    }

    /** Só para testes: zera a memória do processo. */
    public static function flushMemo(): void
    {
        self::$memo = [];
    }
}
