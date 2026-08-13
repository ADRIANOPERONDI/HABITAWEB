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
    ) {
        $this->charges       ??= model(LeadChargeModel::class);
        $this->rules         ??= model(LeadChargeRuleModel::class);
        $this->refModel      ??= model(PropertyExternalRefModel::class);
        $this->propertyModel ??= model(PropertyModel::class);
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
    public function markInvoiced(array $chargeIds, ?int $paymentTransactionId = null, float $creditAppliedTotal = 0.0): int
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
