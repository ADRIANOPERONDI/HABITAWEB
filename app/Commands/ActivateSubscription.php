<?php

namespace App\Commands;

use App\Models\AccountModel;
use App\Models\PlanModel;
use App\Models\UserModel;
use App\Services\PaymentService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Ativa uma assinatura local gratuita pra uma conta, na marra, pelo
 * terminal — mesmo caminho que o checkout/upgrade usam quando o valor
 * efetivo é R$0 (`PaymentService::createFreeLocalSubscription()`), sem
 * passar pelo fluxo de pagamento nem pelo gateway.
 *
 * Uso pretendido: destravar uma conta de teste/onboarding pra ela acessar
 * o painel e testar o resto (ex.: configurar a integração Simob) sem
 * precisar cadastrar cartão nem esperar o cliente pagar de verdade.
 *
 * Por padrão entra na rampa de lançamento (`ramp_started_at` = hoje —
 * ver `LaunchRampService`), os mesmos 6 meses de mensalidade R$0 de quem
 * se cadastra pelo checkout hoje (D1/P6): 6 meses grátis, 50% do mês 7 ao
 * 12, cheio a partir do mês 13. `--sem-rampa` cria a mesma assinatura
 * ACTIVE/R$0, mas SEM esse relógio — fica grátis indefinidamente até
 * alguém trocar o plano na mão (raro; normalmente não é o que se quer).
 *
 * Qualquer assinatura ACTIVE que a conta já tenha é substituída (marcada
 * CANCELADA_POR_TROCA) — mesmo comportamento de `createFreeLocalSubscription()`
 * ao trocar de plano.
 */
class ActivateSubscription extends BaseCommand
{
    protected $group       = 'Assinaturas';
    protected $name        = 'assinatura:ativar';
    protected $description = 'Ativa manualmente uma assinatura gratuita (rampa de lancamento, por padrao) pra uma conta, sem passar pelo checkout/gateway.';
    protected $usage       = 'assinatura:ativar --conta <email-ou-id-da-conta> --plano <CHAVE> [--sem-rampa]';
    protected $options     = [
        '--conta'     => 'E-mail do usuario dono da conta, ou o ID da conta direto.',
        '--plano'     => 'Chave do plano (PRATA, OURO, DIAMANTE...).',
        '--sem-rampa' => 'Cria a assinatura ACTIVE/R$0 fora da rampa (fica gratis indefinidamente, sem data prevista pra virar paga).',
    ];

    public function run(array $params)
    {
        $identificador = CLI::getOption('conta') ?? ($params['conta'] ?? null) ?? CLI::prompt('E-mail do usuário ou ID da conta');
        $chavePlano    = CLI::getOption('plano') ?? ($params['plano'] ?? null) ?? CLI::prompt('Chave do plano (PRATA, OURO, DIAMANTE...)');
        $semRampa      = CLI::getOption('sem-rampa') !== null || array_key_exists('sem-rampa', $params);

        $accountModel = model(AccountModel::class);
        $account = ctype_digit((string) $identificador)
            ? $accountModel->find((int) $identificador)
            : $this->findAccountByEmail((string) $identificador);

        if (! $account) {
            CLI::error("Conta não encontrada para '{$identificador}'.");

            return EXIT_ERROR;
        }

        $plan = model(PlanModel::class)->where('chave', strtoupper((string) $chavePlano))->first();

        if (! $plan) {
            CLI::error("Plano '{$chavePlano}' não encontrado.");

            return EXIT_ERROR;
        }

        if (! $plan->ativo) {
            CLI::error("Plano '{$plan->chave}' está desativado — escolha um plano do catálogo atual.");

            return EXIT_ERROR;
        }

        $rampStartedAt = $semRampa ? null : date('Y-m-d');

        $result = (new PaymentService())->createFreeLocalSubscription(
            (int) $account->id,
            $plan,
            'MONTHLY',
            $rampStartedAt
        );

        if (empty($result['success'])) {
            CLI::error('Falha ao criar a assinatura.');

            return EXIT_ERROR;
        }

        audit_log('subscription.activated_manually', [
            'account_id'  => $account->id,
            'entity_type' => 'subscription',
            'entity_id'   => $result['local_id'],
            'metadata'    => ['plan' => $plan->chave, 'ramp' => ! $semRampa],
        ]);

        CLI::write("Conta #{$account->id} ({$account->nome}) ativada no plano {$plan->nome}.", 'green');
        CLI::write($semRampa
            ? 'Fora da rampa — gratis indefinidamente ate alguem trocar o plano na mao.'
            : 'Na rampa de lancamento — 6 meses gratis, 50% do mes 7 ao 12, cheio a partir do mes 13.', 'yellow');

        return EXIT_SUCCESS;
    }

    /** Mesmo caminho de busca por e-mail já usado em kyc:aprovar/auth:reset-password. */
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
