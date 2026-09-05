<?php

namespace App\Services;

use App\Models\LeadChargeModel;
use App\Models\LeadChargeRuleModel;
use App\Models\PropertyExternalRefModel;
use App\Models\PropertyModel;

/**
 * Cobrança por lead — o motor de "comissão por negócio fechado" original,
 * generalizado. `onLeadReceived` é o gatilho vigente (todo lead recebido é
 * cobrável, ver LeadService); `onLeadClosed` fica como histórico morto: as
 * linhas antigas (`origem = NEGOCIO_FECHADO`) e a tela do superadmin
 * continuam legíveis, só ninguém mais chama o gatilho.
 *
 * O ciclo é PENDING -> APPROVED -> INVOICED -> PAID, com DISPUTED e WAIVED
 * como desvios. Nada é cobrado automaticamente: a apuração nasce PENDING (ou
 * já WAIVED, se a checagem de qualidade reprovar) e só vira fatura depois da
 * janela de contestação.
 */
class LeadChargeService
{
    /** Dias de janela para o tenant contestar antes da aprovação automática. */
    public const CONTEST_WINDOW_DIAS = 7;

    public function __construct(
        private ?LeadChargeModel $charges = null,
        private ?LeadChargeRuleModel $rules = null,
        private ?PropertyExternalRefModel $refModel = null,
        private ?PropertyModel $propertyModel = null,
        private ?LeadQualityService $quality = null,
    ) {
        $this->charges       ??= model(LeadChargeModel::class);
        $this->rules         ??= model(LeadChargeRuleModel::class);
        $this->refModel      ??= model(PropertyExternalRefModel::class);
        $this->propertyModel ??= model(PropertyModel::class);
        $this->quality       ??= new LeadQualityService();
    }

    /**
     * Cobra pelo recebimento do lead — o gatilho vigente a partir da Fase 3.
     *
     * Chamado dentro do `if (!$existingLead)` de `LeadService::trySaveLead`,
     * uma única vez na vida do lead. `tipo_negocio` vem já snapshotado no
     * lead (não é buscado em `properties`) — de propósito, para que o
     * anunciante mudar o anúncio de VENDA para ALUGUEL depois não altere uma
     * cobrança já gerada.
     *
     * NUNCA pode propagar: o visitante não pode ficar sem confirmação porque
     * a cobrança falhou.
     *
     * @return int|null id da cobrança criada
     */
    public function onLeadReceived(object $lead): ?int
    {
        try {
            return $this->applyReceived($lead);
        } catch (\Throwable $e) {
            log_message('error', '[Cobranca] Falha ao apurar recebimento do lead ' . ($lead->id ?? '?') . ': ' . $e->getMessage());

            return null;
        }
    }

    private function applyReceived(object $lead): ?int
    {
        $leadId = (int) ($lead->id ?? 0);

        if ($leadId === 0) {
            return null;
        }

        // Um lead gera no máximo uma cobrança — unique em lead_id como rede
        // de segurança contra reentrada.
        if ($this->charges->findByLead($leadId) !== null) {
            return null;
        }

        $accountId = (int) ($lead->account_id_anunciante ?? 0);

        if ($accountId === 0) {
            return null;
        }

        $account = model(\App\Models\AccountModel::class)->find($accountId);

        if ($account === null || (bool) ($account->cobranca_leads_isenta ?? false)) {
            return null;
        }

        $propertyId   = (int) ($lead->property_id ?? 0);
        $ref          = $propertyId > 0 ? $this->refModel->findByProperty($propertyId) : null;
        $providerCode = $ref?->provider_code;

        // VENDA_ALUGUEL não tem preço definido na proposta comercial (ver
        // premissa pendente de confirmação): trata como ALUGUEL para efeito
        // de regra até o cliente decidir a intenção do visitante.
        $tipoNegocio = (string) ($lead->tipo_negocio ?? '');
        $tipoNegocio = $tipoNegocio === 'VENDA_ALUGUEL' ? 'ALUGUEL' : ($tipoNegocio ?: null);

        // Checagem de qualidade ANTES de calcular quanto cobrar: um lead
        // reprovado nasce WAIVED e nunca chega perto de uma regra de preço.
        $flags = $this->quality->scan($lead);

        if ($flags !== []) {
            return (int) $this->charges->insert([
                'account_id'       => $accountId,
                'provider_code'    => $providerCode,
                'lead_id'          => $leadId,
                'property_id'      => $propertyId > 0 ? $propertyId : null,
                'tipo_negocio'     => $tipoNegocio,
                'origem'           => LeadChargeModel::ORIGEM_LEAD_RECEBIDO,
                'periodo'          => date('Y-m-01'),
                'base_value'       => 0,
                'commission_value' => 0,
                'status'           => LeadChargeModel::STATUS_WAIVED,
                'waived_reason'    => implode(', ', $flags),
            ], true);
        }

        $rule = $this->rules->resolveFor($accountId, $providerCode, $tipoNegocio);

        if ($rule === null) {
            return null;
        }

        // Lead recebido não tem "valor de fechamento" — a base é sempre 0.
        // Modelo PERCENT aplicado aqui sempre resulta em 0 (calculate(0)) e a
        // guarda abaixo evita gravar uma cobrança de graça por regra mal
        // configurada.
        $chargeValue = $rule->calculate(0.0);

        if ($chargeValue <= 0) {
            return null;
        }

        return (int) $this->charges->insert([
            'account_id'       => $accountId,
            'provider_code'    => $providerCode,
            'lead_id'          => $leadId,
            'property_id'      => $propertyId > 0 ? $propertyId : null,
            'rule_id'          => $rule->id,
            'tipo_negocio'     => $tipoNegocio,
            'origem'           => LeadChargeModel::ORIGEM_LEAD_RECEBIDO,
            'periodo'          => date('Y-m-01'),
            'base_value'       => 0,
            'commission_value' => $chargeValue,
            'status'           => LeadChargeModel::STATUS_PENDING,
            'contest_deadline' => date('Y-m-d H:i:s', strtotime('+' . self::CONTEST_WINDOW_DIAS . ' days')),
        ], true);
    }

    /**
     * Apura a comissão de um lead recém-fechado — caminho histórico
     * (`NEGOCIO_FECHADO`). Nada mais chama isto em produção desde a Fase 3;
     * mantido para não apagar o comportamento que o extrato do superadmin
     * ainda precisa entender.
     *
     * @return int|null id da comissão criada
     */
    public function onLeadClosed(object $lead): ?int
    {
        try {
            return $this->applyClosed($lead);
        } catch (\Throwable $e) {
            log_message('error', '[Cobranca] Falha ao apurar fechamento do lead ' . ($lead->id ?? '?') . ': ' . $e->getMessage());

            return null;
        }
    }

    private function applyClosed(object $lead): ?int
    {
        $leadId = (int) ($lead->id ?? 0);

        if ($leadId === 0) {
            return null;
        }

        if ($this->charges->findByLead($leadId) !== null) {
            return null;
        }

        $baseValue = (float) ($lead->closing_value ?? 0);

        if ($baseValue <= 0) {
            return null;
        }

        $propertyId = (int) ($lead->property_id ?? 0);
        $ref        = $propertyId > 0 ? $this->refModel->findByProperty($propertyId) : null;

        if ($ref === null) {
            return null;
        }

        $property     = $this->propertyModel->find($propertyId);
        $tipoNegocio  = $property->tipo_negocio ?? null;
        $accountId    = (int) $ref->account_id;
        $providerCode = (string) $ref->provider_code;

        $rule = $this->rules->resolveFor($accountId, $providerCode, $tipoNegocio);

        if ($rule === null) {
            return null;
        }

        $commissionValue = $rule->calculate($baseValue);

        if ($commissionValue <= 0) {
            return null;
        }

        return (int) $this->charges->insert([
            'account_id'       => $accountId,
            'provider_code'    => $providerCode,
            'lead_id'          => $leadId,
            'property_id'      => $propertyId,
            'rule_id'          => $rule->id,
            'tipo_negocio'     => $tipoNegocio,
            'origem'           => LeadChargeModel::ORIGEM_NEGOCIO_FECHADO,
            'periodo'          => date('Y-m-01'),
            'base_value'       => $baseValue,
            'commission_value' => $commissionValue,
            'status'           => LeadChargeModel::STATUS_PENDING,
            'closed_at'        => $lead->closed_at ?? date('Y-m-d H:i:s'),
        ], true);
    }

    // ----------------------------------------------------------- ciclo de vida

    public function approve(int $chargeId): bool
    {
        $charge = $this->charges->find($chargeId);

        if ($charge === null || ! $charge->isEditable()) {
            return false;
        }

        return $this->charges->markStatus($chargeId, LeadChargeModel::STATUS_APPROVED);
    }

    public function cancel(int $chargeId, ?string $reason = null): bool
    {
        $charge = $this->charges->find($chargeId);

        // Cobrança já faturada não se cancela por aqui: existe uma cobrança no
        // gateway atrelada a ela, e desfazer isso é operação financeira.
        if ($charge === null || ! $charge->isEditable()) {
            return false;
        }

        return $this->charges->markStatus(
            $chargeId,
            LeadChargeModel::STATUS_CANCELLED,
            $reason === null ? [] : ['notes' => mb_substr($reason, 0, 1000)]
        );
    }

    /**
     * O tenant contesta uma cobrança PENDING dentro do prazo. Some do ciclo
     * de aprovação automática até o superadmin resolver.
     */
    public function contest(int $chargeId, int $accountId, string $reason): bool
    {
        $charge = $this->charges->find($chargeId);

        if ($charge === null || (int) $charge->account_id !== $accountId || ! $charge->isContestable()) {
            return false;
        }

        return $this->charges->markStatus($chargeId, LeadChargeModel::STATUS_DISPUTED, [
            'dispute_reason' => mb_substr($reason, 0, 1000),
        ]);
    }

    /**
     * O superadmin resolve uma disputa. Procedente = a contestação tinha
     * razão, a cobrança nunca acontece (WAIVED). Improcedente = a cobrança
     * volta para APPROVED e segue para o próximo fechamento de ciclo — não
     * volta para PENDING, porque o prazo de contestação já foi dado.
     */
    public function resolveDispute(int $chargeId, bool $procedente, ?string $notes = null): bool
    {
        $charge = $this->charges->find($chargeId);

        if ($charge === null || $charge->status !== LeadChargeModel::STATUS_DISPUTED) {
            return false;
        }

        $extra = ['dispute_resolved_at' => date('Y-m-d H:i:s')];

        if ($notes !== null) {
            $extra['notes'] = mb_substr($notes, 0, 1000);
        }

        if ($procedente) {
            $extra['waived_reason'] = 'Disputa procedente: ' . ($notes ?? $charge->dispute_reason ?? '');

            return $this->charges->markStatus($chargeId, LeadChargeModel::STATUS_WAIVED, $extra);
        }

        return $this->charges->markStatus($chargeId, LeadChargeModel::STATUS_APPROVED, $extra);
    }

    /**
     * Aprovação automática: tudo que passou do prazo de contestação sem ser
     * contestado vira APPROVED. Cron diário (`leads:aprovar-cobrancas`) — sem
     * isto, `markInvoiced()` nunca teria o que faturar.
     *
     * @return int quantas foram aprovadas
     */
    public function approveExpired(): int
    {
        $n = 0;

        foreach ($this->charges->pendingPastDeadline() as $charge) {
            if ($this->charges->markStatus((int) $charge->id, LeadChargeModel::STATUS_APPROVED)) {
                $n++;
            }
        }

        return $n;
    }

    /** @return int quantas foram aprovadas */
    public function approveMany(array $ids): int
    {
        $n = 0;

        foreach ($ids as $id) {
            if ($this->approve((int) $id)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Marca como faturadas as cobranças aprovadas de uma conta.
     *
     * A geração da cobrança em si fica com o PaymentService/gateway; aqui só se
     * registra o vínculo. Separar os dois deixa a apuração testável sem tocar
     * em gateway de pagamento.
     *
     * @param int[] $chargeIds
     */
    public function markInvoiced(array $chargeIds, ?int $paymentTransactionId = null): int
    {
        $n = 0;

        foreach ($chargeIds as $id) {
            $charge = $this->charges->find((int) $id);

            if ($charge === null || $charge->status !== LeadChargeModel::STATUS_APPROVED) {
                continue;
            }

            $extra = [];

            if ($paymentTransactionId !== null) {
                $extra['payment_transaction_id'] = $paymentTransactionId;
            }

            $this->charges->markStatus((int) $id, LeadChargeModel::STATUS_INVOICED, $extra);
            $n++;
        }

        return $n;
    }

    /** Chamado quando o pagamento da fatura é confirmado. */
    public function markPaidByTransaction(int $paymentTransactionId): int
    {
        $pendentes = $this->charges
            ->where('payment_transaction_id', $paymentTransactionId)
            ->where('status', LeadChargeModel::STATUS_INVOICED)
            ->findAll();

        foreach ($pendentes as $charge) {
            $this->charges->markStatus((int) $charge->id, LeadChargeModel::STATUS_PAID);
        }

        return count($pendentes);
    }

    /**
     * Fecha o ciclo de um período para uma conta: soma as APPROVED, abate o
     * crédito disponível daquele mesmo período, cobra o restante no gateway
     * (se houver) e marca tudo INVOICED. Expira a sobra de crédito no mesmo
     * instante — ela não pode vazar para pagar o mês seguinte.
     *
     * Chamado pelo comando `leads:fechar-ciclo`, um par (account_id, período)
     * por vez. Aceita `PaymentService`/`LeadCreditService` injetados para que
     * os testes consigam apontar para um gateway fake sem tocar rede.
     *
     * Consumo de crédito e cobrança no gateway andam numa transação: se a
     * chamada ao gateway falhar (rede, credencial), o débito do crédito é
     * desfeito — sem isto, rodar de novo consumiria o que sobrou (ou nada) e
     * a conta perderia o crédito daquele mês sem nunca ter sido cobrada de
     * verdade por ele.
     *
     * @return array{status: string, total: float, credit_applied: float, charged: float, payment_transaction_id: ?int, charge_ids: int[]}
     */
    public function closeCycleForAccount(
        int $accountId,
        string $periodo,
        ?PaymentService $paymentService = null,
        ?LeadCreditService $creditService = null
    ): array {
        $charges = $this->charges->approvedForPeriod($accountId, $periodo);

        if ($charges === []) {
            return [
                'status' => 'nothing', 'total' => 0.0, 'credit_applied' => 0.0,
                'charged' => 0.0, 'payment_transaction_id' => null, 'charge_ids' => [],
            ];
        }

        $chargeIds = array_map(static fn ($c) => (int) $c->id, $charges);
        $total     = round(array_sum(array_map(static fn ($c) => (float) $c->commission_value, $charges)), 2);

        $creditService  ??= new LeadCreditService();
        $paymentService ??= new PaymentService();

        // Idempotência: se este período já gerou fatura (comando rodado de
        // novo, por engano ou por retomada de uma falha anterior), reaproveita
        // em vez de cobrar uma segunda vez no gateway.
        $transactionModel = model(\App\Models\PaymentTransactionModel::class);
        $faturaExistente   = $transactionModel->findLeadInvoice($accountId, $periodo);

        $db = \Config\Database::connect();
        $db->transStart();

        $creditApplied = $creditService->consume($accountId, $periodo, $total, 'lead_charges_cycle');
        $this->distribuirCreditoPorCobranca($charges, $creditApplied);

        $restante = round($total - $creditApplied, 2);

        if ($restante <= 0) {
            $this->markInvoiced($chargeIds, null);
            $creditService->expireRemaining($accountId, $periodo);
            $db->transComplete();

            return [
                'status' => 'invoiced_free', 'total' => $total, 'credit_applied' => $creditApplied,
                'charged' => 0.0, 'payment_transaction_id' => null, 'charge_ids' => $chargeIds,
            ];
        }

        if ($faturaExistente !== null) {
            $this->markInvoiced($chargeIds, (int) $faturaExistente['id']);
            $creditService->expireRemaining($accountId, $periodo);
            $db->transComplete();

            return [
                'status' => 'invoiced_charged', 'total' => $total, 'credit_applied' => $creditApplied,
                'charged' => $restante, 'payment_transaction_id' => (int) $faturaExistente['id'], 'charge_ids' => $chargeIds,
            ];
        }

        $gateway = $paymentService->getActiveGateway();

        if ($gateway === null) {
            // Desfaz o débito de crédito: fica tudo APPROVED, tentável de
            // novo na próxima execução. Melhor um dia de atraso do que
            // faturar sem cobrança nenhuma no gateway.
            $db->transRollback();

            return [
                'status' => 'gateway_indisponivel', 'total' => $total, 'credit_applied' => 0.0,
                'charged' => $restante, 'payment_transaction_id' => null, 'charge_ids' => $chargeIds,
            ];
        }

        try {
            $transactionId = $this->cobrarNoGateway($accountId, $periodo, $restante, $gateway);
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        $this->markInvoiced($chargeIds, $transactionId);
        $creditService->expireRemaining($accountId, $periodo);
        $db->transComplete();

        return [
            'status' => 'invoiced_charged', 'total' => $total, 'credit_applied' => $creditApplied,
            'charged' => $restante, 'payment_transaction_id' => $transactionId, 'charge_ids' => $chargeIds,
        ];
    }

    /** Distribui o crédito consumido entre as cobranças, primeira-a-primeira, só para auditoria. */
    private function distribuirCreditoPorCobranca(array $charges, float $creditoDisponivel): void
    {
        foreach ($charges as $charge) {
            if ($creditoDisponivel <= 0) {
                break;
            }

            $aplicado = min((float) $charge->commission_value, $creditoDisponivel);
            $creditoDisponivel -= $aplicado;

            $this->charges->update((int) $charge->id, ['credit_applied' => $aplicado]);
        }
    }

    /**
     * Resolve o cliente no gateway e cria a cobrança. Mesmo caminho de
     * `PromotionService::applyPackage`: se a assinatura já tem cliente no
     * gateway, reusa; senão cria na hora — não depende de
     * `PaymentService::getOrCreateCustomer()`, que devolve null quando existe
     * assinatura mas sem `asaas_customer_id` (caso das contas em rampa
     * gratuita da Fase 6, que ainda não têm nenhuma cobrança de assinatura).
     *
     * @return int id da payment_transactions criada
     */
    private function cobrarNoGateway(int $accountId, string $periodo, float $amount, \App\PaymentGateways\GatewayInterface $gateway): int
    {
        $accountModel = model(\App\Models\AccountModel::class);
        $account      = $accountModel->find($accountId);

        $subscription = model(\App\Models\SubscriptionModel::class)
            ->where('account_id', $accountId)
            ->orderBy('id', 'DESC')
            ->first();

        $customerId = $subscription->asaas_customer_id ?? null;

        if (empty($customerId)) {
            $customerId = $gateway->createCustomer([
                'name'               => $account->nome,
                'email'              => $account->email,
                'document'           => preg_replace('/\D/', '', (string) ($account->documento ?? '')),
                'phone'              => preg_replace('/\D/', '', (string) ($account->whatsapp ?? $account->telefone ?? '')),
                'external_reference' => (string) $accountId,
            ]);

            // Sem gravar aqui, toda cobrança de lead futura desta conta
            // criaria um customer novo no gateway — a assinatura em rampa
            // gratuita (Fase 6) chega até aqui sem nenhum, já que nunca
            // pagou mensalidade nenhuma.
            if ($subscription !== null) {
                model(\App\Models\SubscriptionModel::class)->update(
                    (int) $subscription->id,
                    ['asaas_customer_id' => $customerId]
                );
            }
        }

        $description = "Cobrança de leads recebidos — competência {$periodo}";

        $payment = $gateway->createPayment((string) $customerId, $amount, [
            'billing_type'       => 'UNDEFINED',
            'description'        => $description,
            'external_reference' => "LEAD_INVOICE_{$accountId}_{$periodo}",
            'due_date'           => date('Y-m-d', strtotime('+3 days')),
        ]);

        $transactionModel = model(\App\Models\PaymentTransactionModel::class);

        return (int) $transactionModel->insert([
            'account_id'             => $accountId,
            'gateway'                => $gateway->getCode(),
            'gateway_transaction_id' => $payment['payment_id'] ?? null,
            'amount'                 => $amount,
            'currency'               => 'BRL',
            'status'                 => 'PENDING',
            'payment_method'         => 'UNDEFINED',
            'type'                   => 'LEAD_INVOICE',
            'description'            => $description,
            'invoice_url'            => $payment['payment_url'] ?? null,
            'metadata'               => json_encode(['account_id' => $accountId, 'periodo' => $periodo]),
        ], true);
    }

    // ------------------------------------------------------------- consultas

    public function listFiltered(array $filters = [], int $perPage = 25): array
    {
        return $this->charges->listFiltered($filters, $perPage);
    }

    public function totalsByStatus(array $filters = []): array
    {
        return $this->charges->totalsByStatus($filters);
    }

    /** Extrato do próprio tenant — somente leitura, para não haver surpresa na fatura. */
    public function statementFor(int $accountId, int $perPage = 25): array
    {
        return $this->charges->listFiltered(['account_id' => $accountId], $perPage);
    }
}
