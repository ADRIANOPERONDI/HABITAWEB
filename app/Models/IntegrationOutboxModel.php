<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Fila de saída das integrações. Ver a migration para o porquê de ser tabela
 * e não Redis.
 */
class IntegrationOutboxModel extends Model
{
    protected $table            = 'integration_outbox';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\IntegrationOutboxItem::class;
    protected $allowedFields    = [
        'account_integration_id', 'event', 'reference_id', 'payload', 'status',
        'attempts', 'next_attempt_at', 'last_error', 'external_ref', 'sent_at',
    ];

    protected array $casts = [
        'payload' => '?json[array]',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_SENT    = 'SENT';
    public const STATUS_FAILED  = 'FAILED';

    public const EVENT_LEAD_CREATED = 'lead.created';

    /** Tentativas antes de desistir. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Espera antes da próxima tentativa, em segundos, por número de tentativas
     * já feitas: 1min, 5min, 30min, 2h, 12h.
     *
     * A escala é larga de propósito: a falha típica aqui é indisponibilidade do
     * servidor da imobiliária, que costuma durar minutos ou horas, não
     * segundos. Repetir de segundo em segundo só faz barulho.
     */
    public const BACKOFF = [60, 300, 1800, 7200, 43200];

    /**
     * Enfileira, sem repetir o que já está na fila para a mesma entidade.
     *
     * A dedupe importa porque o gatilho fica no caminho de criação do lead: um
     * duplo clique no formulário do portal não pode virar dois interesses no
     * CRM da imobiliária.
     */
    public function enqueue(int $accountIntegrationId, string $event, ?int $referenceId, array $payload): ?int
    {
        if ($referenceId !== null) {
            $existing = $this->where('account_integration_id', $accountIntegrationId)
                ->where('event', $event)
                ->where('reference_id', $referenceId)
                ->first();

            if ($existing !== null) {
                return (int) $existing->id;
            }
        }

        return (int) $this->insert([
            'account_integration_id' => $accountIntegrationId,
            'event'                  => $event,
            'reference_id'           => $referenceId,
            'payload'                => $payload,
            'status'                 => self::STATUS_PENDING,
            'attempts'               => 0,
            'next_attempt_at'        => date('Y-m-d H:i:s'),
        ], true);
    }

    /**
     * Itens prontos para tentar agora, mais antigos primeiro.
     *
     * @return \App\Entities\IntegrationOutboxItem[]
     */
    public function due(int $limit = 50): array
    {
        return $this->where('status', self::STATUS_PENDING)
            ->groupStart()
                ->where('next_attempt_at <=', date('Y-m-d H:i:s'))
                ->orWhere('next_attempt_at', null)
            ->groupEnd()
            ->orderBy('next_attempt_at', 'ASC')
            ->findAll($limit);
    }

    public function markSent(int $id, ?string $externalRef = null): bool
    {
        return (bool) $this->update($id, [
            'status'       => self::STATUS_SENT,
            'sent_at'      => date('Y-m-d H:i:s'),
            'external_ref' => $externalRef,
            'last_error'   => null,
        ]);
    }

    /**
     * Registra a falha e reagenda, ou desiste após MAX_ATTEMPTS.
     *
     * @return bool true se ainda vai tentar de novo
     */
    public function markFailed(int $id, int $currentAttempts, string $error): bool
    {
        $attempts = $currentAttempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->update($id, [
                'status'     => self::STATUS_FAILED,
                'attempts'   => $attempts,
                'last_error' => mb_substr($error, 0, 1000),
            ]);

            return false;
        }

        $this->update($id, [
            'status'          => self::STATUS_PENDING,
            'attempts'        => $attempts,
            'next_attempt_at' => date('Y-m-d H:i:s', time() + (self::BACKOFF[$attempts - 1] ?? 43200)),
            'last_error'      => mb_substr($error, 0, 1000),
        ]);

        return true;
    }

    /** Estado do envio de um lead, para o selo na tela de leads. */
    public function statusForLead(int $leadId): ?\App\Entities\IntegrationOutboxItem
    {
        return $this->where('event', self::EVENT_LEAD_CREATED)
            ->where('reference_id', $leadId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /** Recoloca um item falhado na fila (botão "reenviar"). */
    public function retry(int $id): bool
    {
        return (bool) $this->update($id, [
            'status'          => self::STATUS_PENDING,
            'attempts'        => 0,
            'next_attempt_at' => date('Y-m-d H:i:s'),
            'last_error'      => null,
        ]);
    }
}
