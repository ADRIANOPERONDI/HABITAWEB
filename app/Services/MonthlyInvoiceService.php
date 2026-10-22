<?php

namespace App\Services;

use App\Entities\Subscription;
use App\Libraries\Billing\BusinessDay;
use App\Models\PaymentTransactionModel;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;

/**
 * Fatura única mensal (decisão do cliente): todo dia 1 a plataforma emite UMA
 * cobrança por conta com
 *  - a mensalidade do mês (pré-paga), identificando o período; e
 *  - os leads aprovados dos meses anteriores (quantidade × valor, já com o
 *    crédito do plano abatido),
 * vencendo no dia 10 (ou no próximo dia útil), paga por PIX ou boleto.
 *
 * Substitui a recorrência nativa da Asaas e a fatura de leads separada
 * (`leads:fechar-ciclo`). Assinatura antiga ainda com recorrência na Asaas
 * (`asaas_subscription_id`) continua com a mensalidade cobrada por lá; a
 * fatura dela leva só os leads.
 *
 * Pagamento confirmado → `settle()`: leads PAGOS, mensalidade paga até o fim
 * do período. Atraso → mesma suspensão de 5 dias da mensalidade (o tipo
 * FATURA_MENSAL está em PaymentTransactionModel::SUBSCRIPTION_TYPES).
 */
class MonthlyInvoiceService
{
    public const TIPO       = 'FATURA_MENSAL';
    public const DIA_VENCIMENTO = 10;

    private const MESES = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    public function __construct(
        private ?PaymentService $paymentService = null,
        private ?LeadChargeService $leadCharges = null,
        private ?LeadCreditService $credits = null,
    ) {
        $this->paymentService ??= new PaymentService();
        $this->leadCharges    ??= new LeadChargeService();
        $this->credits        ??= new LeadCreditService();
    }

    public static function mesPorExtenso(string $data): string
    {
        $d = new \DateTimeImmutable($data);

        return self::MESES[(int) $d->format('n')] . '/' . $d->format('Y');
    }

    public static function vencimento(string $competencia): string
    {
        return BusinessDay::nextBusinessDay(substr($competencia, 0, 7) . '-' . self::DIA_VENCIMENTO);
    }

    /** Contas a faturar na competência: assinatura ativa ou leads aprovados esperando. */
    public function contasParaFaturar(string $competencia): array
    {
        $comAssinatura = array_map('intval', array_column(
            db_connect()->table('subscriptions')->select('account_id')->where('status', 'ACTIVE')->distinct()->get()->getResultArray(),
            'account_id'
        ));
        $comLeads = array_map(
            static fn (array $p) => $p['account_id'],
            model(\App\Models\LeadChargeModel::class)->approvedAccountPeriods($competencia)
        );

        $ids = array_values(array_unique(array_merge($comAssinatura, $comLeads)));
        sort($ids);

        return $ids;
    }

    /**
     * O que entraria na fatura da conta, sem gravar nada. Usado pelo
     * `--dry-run` e pela própria emissão.
     *
     * @return array{mensalidade: ?array{valor: float, plano: string, inicio: string, fim: string}, leads: array{quantidade: int, total: float}}
     */
    public function preview(int $accountId, string $competencia): array
    {
        return [
            'mensalidade' => $this->linhaMensalidade($accountId, $competencia),
            'leads'       => $this->previewLeads($accountId, $competencia),
        ];
    }

    /**
     * Emite a fatura da conta na competência (AAAA-MM-01). Idempotente: se a
     * fatura do mês já existe, não cobra de novo.
     *
     * @return array{status: string, total: float, payment_transaction_id: ?int}
     */
    public function emitir(int $accountId, string $competencia): array
    {
        $competencia = substr($competencia, 0, 7) . '-01';
        $txModel     = model(PaymentTransactionModel::class);

        if ($existente = $this->faturaExistente($accountId, $competencia)) {
            return ['status' => 'ja_emitida', 'total' => (float) $existente['amount'], 'payment_transaction_id' => (int) $existente['id']];
        }

        $mensalidade = $this->linhaMensalidade($accountId, $competencia);

        $db = db_connect();
        $db->transStart();

        $leads = $this->leadCharges->reserveForInvoice($accountId, $competencia, $this->credits);
        $total = round(($mensalidade['valor'] ?? 0) + $leads['restante'], 2);

        if ($total <= 0) {
            // Leads cobertos pelo crédito (ou nada a cobrar): fecha sem gateway.
            $this->leadCharges->markInvoiced($leads['charge_ids'], null);
            $db->transComplete();

            return ['status' => $leads['charge_ids'] === [] ? 'nada' : 'sem_valor', 'total' => 0.0, 'payment_transaction_id' => null];
        }

        $gateway = $this->paymentService->getActiveGateway();
        if ($gateway === null) {
            $db->transRollback();

            return ['status' => 'gateway_indisponivel', 'total' => $total, 'payment_transaction_id' => null];
        }

        $vencimento = self::vencimento($competencia);
        $descricao  = $this->descricao($competencia, $mensalidade, $leads);
        $subscription = model(SubscriptionModel::class)->where('account_id', $accountId)->where('status', 'ACTIVE')->orderBy('id', 'DESC')->first();

        try {
            $customerId = $this->paymentService->ensureGatewayCustomer($accountId, $gateway);
            $payment    = $gateway->createPayment($customerId, $total, [
                // Pagador escolhe PIX ou boleto na página da fatura; pago por um,
                // a cobrança é quitada e o outro não gera pagamento duplicado.
                'billing_type'       => 'UNDEFINED',
                'description'        => $descricao,
                'external_reference' => 'FATURA_' . $accountId . '_' . substr($competencia, 0, 7),
                'due_date'           => $vencimento,
            ]);
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $txId = (int) $txModel->insert([
            'account_id'             => $accountId,
            'subscription_id'        => $subscription->id ?? null,
            'gateway'                => $gateway->getCode(),
            'gateway_transaction_id' => $payment['payment_id'] ?? null,
            'gateway_customer_id'    => $customerId,
            'amount'                 => $total,
            'currency'               => 'BRL',
            'status'                 => 'PENDING',
            'payment_method'         => 'UNDEFINED',
            'type'                   => self::TIPO,
            'due_date'               => $vencimento,
            'description'            => $descricao,
            'invoice_url'            => $payment['payment_url'] ?? null,
            'metadata'               => json_encode([
                'competencia' => $competencia,
                'mensalidade' => $mensalidade,
                'leads'       => [
                    'quantidade' => $leads['quantidade'],
                    'total'      => $leads['total'],
                    'credito'    => $leads['credito'],
                    'cobrado'    => $leads['restante'],
                    'periodos'   => $leads['periodos'],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ], true);

        $this->leadCharges->markInvoiced($leads['charge_ids'], $txId);
        $db->transComplete();

        return ['status' => 'emitida', 'total' => $total, 'payment_transaction_id' => $txId];
    }

    /**
     * Efeito do pagamento confirmado de uma fatura mensal (webhook ou sync,
     * via PaymentService::settleTransaction): leads pagos e mensalidade paga
     * até o fim do período cobrado. Idempotente.
     */
    public function settle(array $tx): void
    {
        $this->leadCharges->markPaidByTransaction((int) $tx['id']);

        $meta = is_string($tx['metadata'] ?? null) ? json_decode($tx['metadata'], true) : (array) ($tx['metadata'] ?? []);
        $fim  = $meta['mensalidade']['fim'] ?? null;

        if ($fim && ! empty($tx['subscription_id'])) {
            $sub = model(SubscriptionModel::class)->find((int) $tx['subscription_id']);
            if ($sub && ((string) ($sub->mensalidade_paga_ate ?? '') < $fim)) {
                model(SubscriptionModel::class)->update((int) $sub->id, ['mensalidade_paga_ate' => $fim]);
            }
        }
    }

    /**
     * Mensalidade a cobrar na competência, ou null: assinatura sem
     * mensalidade pela plataforma (recorrência antiga na Asaas), já paga
     * para o período, ou com valor zero (carência negociada).
     *
     * @return array{valor: float, plano: string, inicio: string, fim: string}|null
     */
    private function linhaMensalidade(int $accountId, string $competencia): ?array
    {
        $sub = model(SubscriptionModel::class)->where('account_id', $accountId)->where('status', 'ACTIVE')->orderBy('id', 'DESC')->first();
        if (! $sub || ! empty($sub->asaas_subscription_id)) {
            return null;
        }

        // Gratuita por decisão do administrador (`assinatura:ativar --sem-rampa`):
        // FREE sem carência com prazo nunca vira cobrança sozinha.
        if (($sub->payment_method ?? null) === 'FREE' && empty($sub->ramp_started_at)) {
            return null;
        }

        $plan = model(PlanModel::class)->find((int) $sub->plan_id);
        if (! $plan) {
            return null;
        }

        $inicio = new \DateTimeImmutable(substr($competencia, 0, 7) . '-01');
        $anual  = strtoupper((string) ($sub->billing_cycle ?? 'MONTHLY')) === 'YEARLY';
        $fim    = $anual ? $inicio->modify('+11 months')->modify('last day of this month') : $inicio->modify('last day of this month');

        if (! empty($sub->mensalidade_paga_ate) && substr((string) $sub->mensalidade_paga_ate, 0, 10) >= $inicio->modify('last day of this month')->format('Y-m-d')) {
            return null;
        }

        $valor = (new LaunchRampService($this->paymentService))->amountFor($plan, $anual ? 'YEARLY' : 'MONTHLY', $sub, $inicio->format('Y-m-d'));
        if ($valor <= 0) {
            return null;
        }

        return ['valor' => round($valor, 2), 'plano' => (string) $plan->nome, 'inicio' => $inicio->format('Y-m-d'), 'fim' => $fim->format('Y-m-d')];
    }

    private function previewLeads(int $accountId, string $competencia): array
    {
        $quantidade = 0;
        $total      = 0.0;
        foreach (model(\App\Models\LeadChargeModel::class)->approvedAccountPeriods($competencia) as $p) {
            if ($p['account_id'] !== $accountId) {
                continue;
            }
            foreach (model(\App\Models\LeadChargeModel::class)->approvedForPeriod($accountId, $p['periodo']) as $c) {
                $quantidade++;
                $total += (float) $c->commission_value;
            }
        }

        return ['quantidade' => $quantidade, 'total' => round($total, 2)];
    }

    private function descricao(string $competencia, ?array $mensalidade, array $leads): string
    {
        $fmt    = static fn (float $v) => 'R$ ' . number_format($v, 2, ',', '.');
        $linhas = ['Habitaweb — fatura de ' . self::mesPorExtenso($competencia)];

        if ($mensalidade) {
            $periodo = $mensalidade['inicio'] === substr($mensalidade['fim'], 0, 7) . '-01'
                ? self::mesPorExtenso($mensalidade['inicio'])
                : date('d/m/Y', strtotime($mensalidade['inicio'])) . ' a ' . date('d/m/Y', strtotime($mensalidade['fim']));
            $linhas[] = "Mensalidade do plano {$mensalidade['plano']} ({$periodo}): " . $fmt($mensalidade['valor']);
        }

        if ($leads['quantidade'] > 0 && $leads['restante'] > 0) {
            $meses = implode(', ', array_map([self::class, 'mesPorExtenso'], $leads['periodos']));
            $linha = "Leads recebidos ({$meses}): {$leads['quantidade']} " . ($leads['quantidade'] === 1 ? 'contato' : 'contatos') . ' = ' . $fmt($leads['total']);
            if ($leads['credito'] > 0) {
                $linha .= ' − crédito do plano ' . $fmt($leads['credito']) . ' = ' . $fmt($leads['restante']);
            }
            $linhas[] = $linha;
        }

        return implode("\n", $linhas);
    }

    private function faturaExistente(int $accountId, string $competencia): ?array
    {
        $row = db_connect()->query(
            "SELECT * FROM payment_transactions
             WHERE account_id = ? AND type = ? AND status != 'CANCELLED'
               AND metadata->>'competencia' = ?
             ORDER BY id DESC LIMIT 1",
            [$accountId, self::TIPO, $competencia]
        )->getRowArray();

        return $row ?: null;
    }
}
