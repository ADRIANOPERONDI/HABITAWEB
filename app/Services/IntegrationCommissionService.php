<?php

namespace App\Services;

use App\Models\IntegrationCommissionModel;
use App\Models\IntegrationCommissionRuleModel;
use App\Models\LeadModel;
use App\Models\PropertyExternalRefModel;
use App\Models\PropertyModel;

/**
 * Cobrança por lead fechado nos imóveis vindos de integração.
 *
 * Aproveita o fechamento de lead que já existe no produto: quando um lead vai
 * para CONCLUIDO com closing_value, e o imóvel tem vínculo com uma plataforma
 * externa, apura-se a comissão conforme a regra vigente.
 *
 * O ciclo é PENDING -> APPROVED -> INVOICED -> PAID. Nada é cobrado
 * automaticamente: a apuração nasce PENDING e alguém precisa aprovar. Gerar
 * fatura sozinho sobre um valor que o corretor digitou à mão seria arriscado
 * demais.
 */
class IntegrationCommissionService
{
    public function __construct(
        private ?IntegrationCommissionModel $commissions = null,
        private ?IntegrationCommissionRuleModel $rules = null,
        private ?PropertyExternalRefModel $refModel = null,
        private ?PropertyModel $propertyModel = null,
    ) {
        $this->commissions   ??= model(IntegrationCommissionModel::class);
        $this->rules         ??= model(IntegrationCommissionRuleModel::class);
        $this->refModel      ??= model(PropertyExternalRefModel::class);
        $this->propertyModel ??= model(PropertyModel::class);
    }

    /**
     * Apura a comissão de um lead recém-fechado.
     *
     * Silencioso quando não há o que apurar: lead de imóvel próprio, sem valor
     * de fechamento ou sem regra configurada não é erro.
     *
     * Como roda dentro do fluxo de fechamento do lead, NUNCA pode propagar: o
     * corretor não pode ficar impedido de fechar um negócio porque a apuração
     * de comissão falhou.
     *
     * @return int|null id da comissão criada
     */
    public function onLeadClosed(object $lead): ?int
    {
        try {
            return $this->apply($lead);
        } catch (\Throwable $e) {
            log_message('error', '[Comissao] Falha ao apurar lead ' . ($lead->id ?? '?') . ': ' . $e->getMessage());

            return null;
        }
    }

    private function apply(object $lead): ?int
    {
        $leadId = (int) ($lead->id ?? 0);

        if ($leadId === 0) {
            return null;
        }

        // Um lead gera no máximo uma comissão: reabrir e refechar não cobra
        // duas vezes. Há unique em lead_id como rede de segurança.
        if ($this->commissions->findByLead($leadId) !== null) {
            return null;
        }

        $baseValue = (float) ($lead->closing_value ?? 0);

        if ($baseValue <= 0) {
            return null;
        }

        $propertyId = (int) ($lead->property_id ?? 0);
        $ref        = $propertyId > 0 ? $this->refModel->findByProperty($propertyId) : null;

        // Só imóvel de integração gera comissão. Imóvel cadastrado à mão pelo
        // tenant é dele, e cobrar por ele seria cobrar duas vezes o mesmo plano.
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

        return (int) $this->commissions->insert([
            'account_id'       => $accountId,
            'provider_code'    => $providerCode,
            'lead_id'          => $leadId,
            'property_id'      => $propertyId,
            'rule_id'          => $rule->id,
            'tipo_negocio'     => $tipoNegocio,
            'base_value'       => $baseValue,
            'commission_value' => $commissionValue,
            'status'           => IntegrationCommissionModel::STATUS_PENDING,
            'closed_at'        => $lead->closed_at ?? date('Y-m-d H:i:s'),
        ], true);
    }

    // ----------------------------------------------------------- ciclo de vida

    public function approve(int $commissionId): bool
    {
        $commission = $this->commissions->find($commissionId);

        if ($commission === null || ! $commission->isEditable()) {
            return false;
        }

        return $this->commissions->markStatus($commissionId, IntegrationCommissionModel::STATUS_APPROVED);
    }

    public function cancel(int $commissionId, ?string $reason = null): bool
    {
        $commission = $this->commissions->find($commissionId);

        // Comissão já faturada não se cancela por aqui: existe uma cobrança no
        // gateway atrelada a ela, e desfazer isso é operação financeira.
        if ($commission === null || ! $commission->isEditable()) {
            return false;
        }

        return $this->commissions->markStatus(
            $commissionId,
            IntegrationCommissionModel::STATUS_CANCELLED,
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
     * Marca como faturadas as comissões aprovadas de uma conta.
     *
     * A geração da cobrança em si fica com o PaymentService/gateway; aqui só se
     * registra o vínculo. Separar os dois deixa a apuração testável sem tocar
     * em gateway de pagamento.
     *
     * @param int[] $commissionIds
     */
    public function markInvoiced(array $commissionIds, ?int $paymentTransactionId = null): int
    {
        $n = 0;

        foreach ($commissionIds as $id) {
            $commission = $this->commissions->find((int) $id);

            if ($commission === null || $commission->status !== IntegrationCommissionModel::STATUS_APPROVED) {
                continue;
            }

            $this->commissions->markStatus(
                (int) $id,
                IntegrationCommissionModel::STATUS_INVOICED,
                $paymentTransactionId === null ? [] : ['payment_transaction_id' => $paymentTransactionId]
            );
            $n++;
        }

        return $n;
    }

    /** Chamado quando o pagamento da fatura é confirmado. */
    public function markPaidByTransaction(int $paymentTransactionId): int
    {
        $pendentes = $this->commissions
            ->where('payment_transaction_id', $paymentTransactionId)
            ->where('status', IntegrationCommissionModel::STATUS_INVOICED)
            ->findAll();

        foreach ($pendentes as $commission) {
            $this->commissions->markStatus((int) $commission->id, IntegrationCommissionModel::STATUS_PAID);
        }

        return count($pendentes);
    }

    // ------------------------------------------------------------- consultas

    public function listFiltered(array $filters = [], int $perPage = 25): array
    {
        return $this->commissions->listFiltered($filters, $perPage);
    }

    public function totalsByStatus(array $filters = []): array
    {
        return $this->commissions->totalsByStatus($filters);
    }

    /** Extrato do próprio tenant — somente leitura, para não haver surpresa na fatura. */
    public function statementFor(int $accountId, int $perPage = 25): array
    {
        return $this->commissions->listFiltered(['account_id' => $accountId], $perPage);
    }
}
