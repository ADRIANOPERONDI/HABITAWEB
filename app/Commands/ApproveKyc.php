<?php

namespace App\Commands;

use App\Models\AccountModel;
use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Aprova a verificação de identidade (KYC) de uma conta pelo terminal —
 * mesmo efeito do botão "Aprovar" em admin/verification/show/(:num)
 * (`VerificationController::update()`), sem precisar abrir o painel.
 *
 * Uso pretendido: cliente já confirmado por fora (WhatsApp, telefone) mas
 * travado no filtro `admin_auth` por algum motivo do fluxo de upload
 * (documento ilegível, falha de liveness, etc.) — libera o painel na hora,
 * registrando a aprovação do mesmo jeito que a tela registraria
 * (`accounts.verification_notes` + `audit_logs`).
 */
class ApproveKyc extends BaseCommand
{
    protected $group       = 'Auth';
    protected $name        = 'kyc:aprovar';
    protected $description = 'Aprova a verificacao de identidade (KYC) de uma conta, liberando o acesso ao painel.';
    protected $usage       = 'kyc:aprovar <email-do-usuario-ou-id-da-conta>';

    public function run(array $params)
    {
        $identificador = $params[0] ?? CLI::prompt('E-mail do usuário ou ID da conta');

        $accountModel = model(AccountModel::class);
        $account = ctype_digit((string) $identificador)
            ? $accountModel->find((int) $identificador)
            : $this->findAccountByEmail((string) $identificador);

        if (! $account) {
            CLI::error("Conta não encontrada para '{$identificador}'.");

            return EXIT_ERROR;
        }

        if ($account->verification_status === 'APPROVED') {
            CLI::write("Conta #{$account->id} ({$account->nome}) já está APPROVED. Nada a fazer.", 'yellow');

            return EXIT_SUCCESS;
        }

        $accountModel->update($account->id, [
            'verification_status' => 'APPROVED',
            'is_verified'         => true,
            'verification_notes'  => 'Aprovado via spark kyc:aprovar em ' . date('d/m/Y H:i'),
        ]);

        audit_log('kyc.verification_reviewed', [
            'account_id'  => $account->id,
            'entity_type' => 'account',
            'entity_id'   => $account->id,
            'metadata'    => ['status' => 'APPROVED', 'via' => 'cli'],
        ]);

        CLI::write("Conta #{$account->id} ({$account->nome}) aprovada — painel liberado.", 'green');

        return EXIT_SUCCESS;
    }

    /** Mesmo caminho de busca por e-mail já usado em auth:reset-password e reset:user. */
    private function findAccountByEmail(string $email): ?object
    {
        $db = \Config\Database::connect();

        $identity = $db->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->get()
            ->getFirstRow();

        if (! $identity) {
            return null;
        }

        $user = model(UserModel::class)->find($identity->user_id);

        if (! $user || ! $user->account_id) {
            return null;
        }

        return model(AccountModel::class)->find($user->account_id);
    }
}
