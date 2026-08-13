<?php

namespace App\Services;

use App\Models\LeadCreditLedgerModel;

/**
 * Carteira de créditos de lead (benefício mensal do Ouro/Diamante).
 *
 * Ver a migration de `lead_credit_ledger`: não existe tabela de saldo, o
 * saldo do período é sempre recalculado a partir do ledger. Política fixada
 * no desenho (ver plano): crédito é casado com o próprio período — o de
 * agosto só paga fatura de agosto, e a sobra expira no fechamento em vez de
 * acumular.
 */
class LeadCreditService
{
    public const ORIGEM_PLANO_MENSAL   = 'PLANO_MENSAL';
    public const ORIGEM_CONSUMO_FATURA = 'CONSUMO_FATURA';
    public const ORIGEM_EXPIRACAO      = 'EXPIRACAO';
    public const ORIGEM_AJUSTE_MANUAL  = 'AJUSTE_MANUAL';

    public function __construct(private ?LeadCreditLedgerModel $ledger = null)
    {
        $this->ledger ??= model(LeadCreditLedgerModel::class);
    }

    public function balanceFor(int $accountId, string $periodo): float
    {
        return $this->ledger->balanceFor($accountId, $periodo);
    }

    /**
     * Concede o crédito mensal a toda conta com assinatura vigente cujo
     * plano dá crédito. Idempotente por construção: o índice único parcial
     * em `(account_id, periodo)` para `origem = PLANO_MENSAL` faz o segundo
     * INSERT do mês virar no-op, não erro — por isso o INSERT vai por SQL
     * direto com `ON CONFLICT DO NOTHING`, não por `Model::insert()` (que
     * abortaria a transação inteira no Postgres ao bater no conflito).
     *
     * @return int quantas contas receberam crédito nesta chamada
     */
    public function grantMonthly(?string $periodo = null): int
    {
        $periodo ??= date('Y-m-01');

        $db = \Config\Database::connect();

        $elegiveis = $db->query(
            "SELECT DISTINCT ON (s.account_id) s.account_id, p.credito_leads_mensal
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.status IN ('ACTIVE', 'TRIAL')
             ORDER BY s.account_id, s.id DESC"
        )->getResultArray();

        $concedidas = 0;

        foreach ($elegiveis as $row) {
            $credito = (float) ($row['credito_leads_mensal'] ?? 0);

            if ($credito <= 0) {
                continue;
            }

            $inserted = $db->query(
                "INSERT INTO lead_credit_ledger (account_id, tipo, origem, amount, periodo, created_at)
                 VALUES (?, 'CREDITO', ?, ?, ?, NOW())
                 ON CONFLICT (account_id, periodo) WHERE origem = 'PLANO_MENSAL' AND tipo = 'CREDITO' DO NOTHING
                 RETURNING id",
                [(int) $row['account_id'], self::ORIGEM_PLANO_MENSAL, $credito, $periodo]
            )->getRow();

            if ($inserted !== null) {
                $concedidas++;
            }
        }

        return $concedidas;
    }

    /**
     * Consome até `$amount` do saldo disponível no período, para abater uma
     * fatura de lead. Nunca consome mais do que existe — quem chama decide o
     * que fazer com a diferença (cobrar no gateway).
     *
     * @return float quanto foi efetivamente consumido
     */
    public function consume(
        int $accountId,
        string $periodo,
        float $amount,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): float {
        if ($amount <= 0) {
            return 0.0;
        }

        $saldo    = $this->balanceFor($accountId, $periodo);
        $consumir = round(min($saldo, $amount), 2);

        if ($consumir <= 0) {
            return 0.0;
        }

        $this->ledger->insert([
            'account_id'     => $accountId,
            'tipo'           => LeadCreditLedgerModel::TIPO_DEBITO,
            'origem'         => self::ORIGEM_CONSUMO_FATURA,
            'amount'         => $consumir,
            'periodo'        => $periodo,
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
        ]);

        return $consumir;
    }

    /**
     * Expira o que sobrou do crédito do período — chamado no mesmo instante
     * do fechamento de ciclo, depois do consumo. Sem isto, o saldo de agosto
     * vazaria para pagar a fatura de setembro.
     *
     * @return float quanto foi expirado
     */
    public function expireRemaining(int $accountId, string $periodo): float
    {
        $saldo = $this->balanceFor($accountId, $periodo);

        if ($saldo <= 0) {
            return 0.0;
        }

        $this->ledger->insert([
            'account_id' => $accountId,
            'tipo'       => LeadCreditLedgerModel::TIPO_DEBITO,
            'origem'     => self::ORIGEM_EXPIRACAO,
            'amount'     => $saldo,
            'periodo'    => $periodo,
        ]);

        return $saldo;
    }
}
