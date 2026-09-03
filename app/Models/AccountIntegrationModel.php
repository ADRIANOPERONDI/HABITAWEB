<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Integração contratada por um tenant (uma linha por conta + conector).
 */
class AccountIntegrationModel extends Model
{
    protected $table            = 'account_integrations';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = \App\Entities\AccountIntegration::class;
    protected $allowedFields    = [
        'account_id', 'provider_code', 'is_active', 'status',
        'last_test_at', 'last_test_message', 'last_sync_at', 'sync_cursor', 'settings',
        'sync_priority_requested_at', 'sync_locked_until',
    ];

    /** Janela de "vencido" pro sync automático — ver dueForSync(). */
    private const DUE_STALENESS_MINUTES = 25;

    // is_active PRECISA do cast no model: Postgres devolve 'f', que é truthy
    // em PHP, e o comando de sync rodaria em integrações desligadas.
    protected array $casts = [
        'is_active'   => 'boolean',
        'sync_cursor' => '?json[array]',
        'settings'    => '?json[array]',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    public const STATUS_PENDING   = 'PENDING';
    public const STATUS_CONNECTED = 'CONNECTED';
    public const STATUS_ERROR     = 'ERROR';
    public const STATUS_PAUSED    = 'PAUSED';

    public function findForAccount(int $accountId, string $providerCode): ?\App\Entities\AccountIntegration
    {
        return $this->where('account_id', $accountId)
            ->where('provider_code', $providerCode)
            ->first();
    }

    /** @return \App\Entities\AccountIntegration[] */
    public function listForAccount(int $accountId): array
    {
        return $this->where('account_id', $accountId)->findAll();
    }

    /**
     * Integrações elegíveis ao sync — prioritárias primeiro, depois as mais
     * vencidas.
     *
     * ERROR fica de fora de propósito: se a credencial está inválida, insistir
     * só empilha erro. O tenant reabilita testando a conexão.
     *
     * Sem o filtro de "vencido" (o `groupStart` abaixo), esta query devolveria
     * TODA integração ativa a cada chamada — o intervalo de sync hoje é
     * garantido só pela frequência do cron, não pela query. Isso era inofensivo
     * enquanto o cron rodava a cada 30 min; deixa de ser inofensivo quando o
     * cron passa a rodar a cada 1 min (pra atender o clique de "sincronizar
     * agora" com latência baixa) sem martelar a origem de todo tenant a cada
     * minuto.
     */
    public function dueForSync(?string $providerCode = null, ?int $accountId = null, int $limit = 50): array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::DUE_STALENESS_MINUTES . ' minutes'));

        $builder = $this->where('is_active', true)
            ->whereIn('status', [self::STATUS_CONNECTED, self::STATUS_PENDING])
            ->groupStart() // prioridade pedida OU vencido (nunca sincronizou ou passou da janela)
                ->where('sync_priority_requested_at IS NOT NULL', null, false)
                ->orWhere('last_sync_at', null)
                ->orWhere('last_sync_at <=', $cutoff)
            ->groupEnd();

        if ($providerCode !== null) {
            $builder->where('provider_code', $providerCode);
        }

        if ($accountId !== null) {
            $builder->where('account_id', $accountId);
        }

        return $builder
            ->orderBy('(sync_priority_requested_at IS NOT NULL)', 'DESC')
            ->orderBy('last_sync_at', 'ASC')
            ->findAll($limit);
    }

    public function markTested(int $id, bool $ok, string $message): bool
    {
        $data = [
            'status'            => $ok ? self::STATUS_CONNECTED : self::STATUS_ERROR,
            'last_test_at'      => date('Y-m-d H:i:s'),
            'last_test_message' => mb_substr($message, 0, 500),
        ];

        // Primeiro teste bem-sucedido liga o sync automático sozinho — sem
        // isso, o estado normal depois de configurar e testar é "conectado,
        // mas is_active=false", e nem o sync automático nem o envio de leads
        // (IntegrationOutboxService) fazem nada até o tenant achar, sozinho,
        // um botão separado de "ativar" que a tela nem destaca.
        //
        // O sinal de "é o primeiro teste" é last_test_at, não last_sync_at:
        // uma integração pode ser testada várias vezes sem nunca chegar a
        // sincronizar. Reativar toda vez que is_active estiver falso faria
        // uma pausa DELIBERADA do tenant durar até o próximo clique em
        // "Testar conexão" — que ele aperta justamente pra conferir se ainda
        // está tudo certo, não pra reativar nada.
        if ($ok) {
            $atual = $this->find($id);

            if ($atual !== null && ! $atual->is_active && $atual->last_test_at === null) {
                $data['is_active'] = true;
            }
        }

        return (bool) $this->update($id, $data);
    }
}
