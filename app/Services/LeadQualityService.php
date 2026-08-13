<?php

namespace App\Services;

use App\Models\AccountModel;
use App\Models\LeadModel;
use CodeIgniter\Config\Factories;

/**
 * Checagem de qualidade rodada antes de cobrar um lead recebido.
 *
 * Não bloqueia o lead — ele é salvo e o anunciante é notificado normalmente.
 * Só decide se a cobrança nasce PENDING (vai para o ciclo normal) ou já
 * nasce WAIVED (nunca chega à fatura). Reprovar rápido demais é generosidade
 * cara, mas cobrar lead falso é o tipo de coisa que o cliente descobre na
 * primeira conferência de fatura e não perdoa.
 */
class LeadQualityService
{
    private const DISPOSABLE_EMAIL_DOMAINS = [
        'mailinator.com', 'tempmail.com', '10minutemail.com', 'guerrillamail.com',
        'yopmail.com', 'throwawaymail.com', 'trashmail.com', 'getnada.com',
        'fakeinbox.com', 'sharklasers.com', 'dispostable.com', 'maildrop.cc',
    ];

    private const IP_BURST_WINDOW_MINUTES = 10;
    private const IP_BURST_THRESHOLD      = 3;

    public function __construct(
        private ?LeadModel $leadModel = null,
        private ?AccountModel $accountModel = null,
    ) {
        $this->leadModel    ??= Factories::models(LeadModel::class);
        $this->accountModel ??= Factories::models(AccountModel::class);
    }

    /**
     * @return string[] motivos de reprovação; vazio = lead limpo
     */
    public function scan(object $lead): array
    {
        $flags = [];

        if ($this->hasInvalidPhone($lead)) {
            $flags[] = 'telefone_invalido';
        }

        if ($this->hasDisposableEmail($lead)) {
            $flags[] = 'email_descartavel';
        }

        if ($this->hasIpBurst($lead)) {
            $flags[] = 'rajada_mesmo_ip';
        }

        if ($this->isSelfLead($lead)) {
            $flags[] = 'auto_lead_anunciante';
        }

        return $flags;
    }

    /** Telefone informado mas curto demais para ser um número real (BR: 10-11 dígitos). */
    private function hasInvalidPhone(object $lead): bool
    {
        $telefone = (string) ($lead->telefone_visitante ?? '');

        if ($telefone === '') {
            return false;
        }

        $digitos = preg_replace('/\D/', '', $telefone);

        return mb_strlen($digitos) < 10;
    }

    private function hasDisposableEmail(object $lead): bool
    {
        $email = (string) ($lead->email_visitante ?? '');

        if (! str_contains($email, '@')) {
            return false;
        }

        $dominio = mb_strtolower(trim(explode('@', $email)[1] ?? ''));

        return in_array($dominio, self::DISPOSABLE_EMAIL_DOMAINS, true);
    }

    /**
     * Vários leads do mesmo IP em pouco tempo — não necessariamente para o
     * mesmo imóvel. Um visitante genuíno não manda 3+ mensagens em 10
     * minutos para imóveis diferentes; um script sim.
     */
    private function hasIpBurst(object $lead): bool
    {
        $ip = (string) ($lead->ip_address ?? '');

        if ($ip === '') {
            return false;
        }

        $limite = date('Y-m-d H:i:s', strtotime('-' . self::IP_BURST_WINDOW_MINUTES . ' minutes'));

        $count = $this->leadModel
            ->where('ip_address', $ip)
            ->where('created_at >=', $limite)
            ->countAllResults();

        return $count >= self::IP_BURST_THRESHOLD;
    }

    /** O próprio anunciante clicando no formulário do imóvel dele. */
    private function isSelfLead(object $lead): bool
    {
        $email = (string) ($lead->email_visitante ?? '');

        if ($email === '') {
            return false;
        }

        $accountId = (int) ($lead->account_id_anunciante ?? 0);

        if ($accountId === 0) {
            return false;
        }

        $account = $this->accountModel->find($accountId);

        return $account !== null
            && $account->email !== null
            && mb_strtolower($account->email) === mb_strtolower($email);
    }
}
