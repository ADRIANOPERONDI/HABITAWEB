<?php

namespace App\Services;

use App\Models\AccountModel;
use App\Models\PaymentTransactionModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Avisos de vencimento por e-mail (decisão do cliente, item 11 da revisão):
 * antes do vencimento, no dia, durante o atraso e na suspensão. Roda uma vez
 * por dia (`spark cobrancas:avisar-vencimento`) e cada fatura recebe cada
 * aviso no máximo uma vez (`billing_reminders`).
 *
 * A etapa é escolhida pelos dias em relação ao vencimento — a mais avançada
 * que já se aplica. Assim, se o cron falhar um dia, o próximo manda o aviso
 * certo para o momento, em vez de um aviso atrasado.
 */
class BillingReminderService
{
    /** Etapa => dias em relação ao vencimento (negativo = antes). */
    public const ETAPAS = [
        'antes_3'    => -3,
        'vence_hoje' => 0,
        'atraso_1'   => 1,
        'atraso_4'   => PaymentTransactionModel::DIAS_ATRASO_SUSPENSAO - 1,
        'suspensa'   => PaymentTransactionModel::DIAS_ATRASO_SUSPENSAO,
    ];

    /** Fatura de leads ainda não suspende a conta (decisão P3): sem as etapas de suspensão. */
    private const ETAPAS_SEM_SUSPENSAO = ['antes_3', 'vence_hoje', 'atraso_1'];

    /** Fatura vencida há mais tempo que isto não recebe mais aviso automático. */
    private const LIMITE_DIAS_ATRASO = 30;

    private BaseConnection $db;
    private NotificationService $notifier;

    public function __construct(?NotificationService $notifier = null, ?BaseConnection $db = null)
    {
        $this->notifier = $notifier ?? new NotificationService();
        $this->db       = $db ?? db_connect();
    }

    /** Etapa que se aplica a uma fatura com este número de dias de atraso (negativo = antes de vencer). */
    public static function etapaPara(int $diasDeAtraso, bool $suspende): ?string
    {
        $escolhida = null;
        foreach (self::ETAPAS as $etapa => $offset) {
            if (! $suspende && ! in_array($etapa, self::ETAPAS_SEM_SUSPENSAO, true)) {
                continue;
            }
            if ($diasDeAtraso >= $offset) {
                $escolhida = $etapa;
            }
        }

        return $escolhida;
    }

    /**
     * @return list<array{transaction_id: int, account_id: int, etapa: string, email: bool}>
     */
    public function run(?string $hoje = null, bool $dryRun = false): array
    {
        $hoje = $hoje ?? date('Y-m-d');
        $ref  = new \DateTimeImmutable($hoje);

        $faturas = $this->db->table('payment_transactions')
            ->whereIn('status', ['PENDING', 'AWAITING_PAYMENT', 'OVERDUE'])
            ->where('due_date IS NOT NULL', null, false)
            ->where('due_date <=', $ref->modify('+3 days')->format('Y-m-d'))
            ->where('due_date >=', $ref->modify('-' . self::LIMITE_DIAS_ATRASO . ' days')->format('Y-m-d'))
            ->groupStart()
                ->whereIn('type', array_merge(PaymentTransactionModel::SUBSCRIPTION_TYPES, ['LEAD_INVOICE']))
                ->orWhere('type', null)
            ->groupEnd()
            ->orderBy('due_date', 'ASC')
            ->get()->getResult();

        $accounts  = model(AccountModel::class);
        $resultado = [];

        foreach ($faturas as $fatura) {
            $dias     = (int) (new \DateTimeImmutable(substr((string) $fatura->due_date, 0, 10)))->diff($ref)->format('%r%a');
            $suspende = ($fatura->type ?? null) !== 'LEAD_INVOICE';
            $etapa    = self::etapaPara($dias, $suspende);

            if ($etapa === null || $this->jaEnviado((int) $fatura->id, $etapa)) {
                continue;
            }

            $account = $accounts->find((int) $fatura->account_id);
            if (! $account) {
                continue;
            }

            $email = false;
            if (! $dryRun) {
                $email = $this->notifier->notifyBillingReminder($account, $fatura, $etapa, $suspende);
                $this->registrar((int) $fatura->id, (int) $fatura->account_id, $etapa, $email);
            }

            $resultado[] = ['transaction_id' => (int) $fatura->id, 'account_id' => (int) $fatura->account_id, 'etapa' => $etapa, 'email' => $email];
        }

        return $resultado;
    }

    private function jaEnviado(int $transactionId, string $etapa): bool
    {
        return $this->db->table('billing_reminders')
            ->where('payment_transaction_id', $transactionId)
            ->where('etapa', $etapa)
            ->countAllResults() > 0;
    }

    private function registrar(int $transactionId, int $accountId, string $etapa, bool $email): void
    {
        $this->db->table('billing_reminders')->insert([
            'payment_transaction_id' => $transactionId,
            'account_id'             => $accountId,
            'etapa'                  => $etapa,
            'email_enviado'          => $email,
            'sent_at'                => date('Y-m-d H:i:s'),
        ]);
    }
}
