<?php

namespace App\Commands;

use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use App\Services\LaunchRampService;
use App\Services\PaymentService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Aplica as transições de faixa da rampa de lançamento (Fase 6): para cada
 * assinatura ACTIVE com `ramp_started_at` preenchido, compara o percentual
 * de hoje (`LaunchRampService::percentFor`) com o último gravado
 * (`ramp_percent_atual`).
 *
 * Duas transições possíveis, tratadas de forma bem diferente:
 *
 * - **50%→100%** (ou qualquer virada entre duas faixas pagas): a assinatura
 *   JÁ existe no gateway com um método de pagamento que o cliente já usa —
 *   corrigir o valor é mecânico e seguro. Feito automaticamente via
 *   `PaymentService::updateSubscriptionAmount()`.
 * - **0%→qualquer coisa** (a PRIMEIRA cobrança real da conta): é o momento
 *   comercialmente mais frágil do modelo — o cliente nunca escolheu forma
 *   de pagamento para a assinatura recorrente, e criar uma cobrança real
 *   sem aviso prévio (a proposta comercial pede e-mail com 30 dias de
 *   antecedência, infraestrutura que este comando não tem) é risco
 *   demais para automatizar sem supervisão. Este comando só REGISTRA a
 *   transição (`audit_log`) e reporta como "requer ação manual" — quem
 *   completa a virada é o operador, via `admin/subscription` (o mesmo
 *   fluxo do tenant, já ciente da rampa) ou contato comercial direto.
 *
 * `--dry-run` não grava nada: lista as transições dos próximos 30 dias
 * (`LaunchRampService::nextTransition`), o relatório que o cliente usa para
 * prever caixa.
 */
class ApplyLaunchRamp extends BaseCommand
{
    protected $group       = 'Assinaturas';
    protected $name        = 'assinaturas:aplicar-rampa';
    protected $description = 'Aplica transicoes de faixa da rampa de lancamento nas assinaturas ativas.';
    protected $usage       = 'assinaturas:aplicar-rampa [--dry-run]';
    protected $options     = [
        '--dry-run' => 'Nao grava nada, so lista as transicoes dos proximos 30 dias.',
    ];

    public function run(array $params)
    {
        // CLI::getOption() cobre a invocação real (`php spark ...`); o array
        // $params cobre a chamada em processo via helper command() (usado
        // pelos testes) — ele não popula o estado estático de CLI::getOption().
        $dryRun = CLI::getOption('dry-run') !== null || array_key_exists('dry-run', $params);

        $subscriptionModel = model(SubscriptionModel::class);
        $rampService       = new LaunchRampService();

        $subscriptions = $subscriptionModel
            ->where('status', 'ACTIVE')
            ->where('ramp_started_at IS NOT NULL', null, false)
            ->findAll();

        if ($subscriptions === []) {
            CLI::write('Nenhuma assinatura na rampa. Nada a fazer.', 'green');

            return;
        }

        if ($dryRun) {
            $this->printDryRun($subscriptions, $rampService);

            return;
        }

        $this->applyTransitions($subscriptions, $rampService);
    }

    private function printDryRun(array $subscriptions, LaunchRampService $rampService): void
    {
        CLI::write('Rampa de lancamento — transicoes previstas nos proximos 30 dias (dry-run)', 'yellow');

        $limite = date('Y-m-d', strtotime('+30 days'));
        $rows   = [];

        foreach ($subscriptions as $subscription) {
            $proxima = $rampService->nextTransition($subscription);
            if ($proxima === null || $proxima['date'] > $limite) {
                continue;
            }

            $rows[] = [
                $subscription->account_id,
                $subscription->id,
                $proxima['date'],
                $proxima['from_percent'] . '%',
                $proxima['to_percent'] . '%',
                empty($subscription->asaas_subscription_id) ? 'cria assinatura (acao manual)' : 'atualiza valor (automatico)',
            ];
        }

        if ($rows === []) {
            CLI::write('Nenhuma transicao nos proximos 30 dias.', 'green');

            return;
        }

        CLI::table($rows, ['Conta', 'Assinatura', 'Data', 'De', 'Para', 'Acao']);
    }

    private function applyTransitions(array $subscriptions, LaunchRampService $rampService): void
    {
        $planModel   = model(PlanModel::class);
        $resumo      = ['sem_mudanca' => 0, 'baseline' => 0, 'atualizado' => 0, 'acao_manual' => 0, 'erro' => 0];

        foreach ($subscriptions as $subscription) {
            try {
                $percentAtual  = $rampService->percentFor($subscription);
                $percentGravado = $subscription->ramp_percent_atual;

                if ($percentGravado === null) {
                    // Primeiro contato deste comando com a assinatura: só
                    // estabelece a base, sem tratar como transição.
                    model(SubscriptionModel::class)->update($subscription->id, ['ramp_percent_atual' => $percentAtual]);
                    $resumo['baseline']++;
                    CLI::write("  conta {$subscription->account_id}: baseline em {$percentAtual}%");

                    continue;
                }

                if ((int) $percentGravado === $percentAtual) {
                    $resumo['sem_mudanca']++;

                    continue;
                }

                if (empty($subscription->asaas_subscription_id)) {
                    // 0%→X%: primeira cobrança real. Não automatiza — ver
                    // docblock da classe.
                    audit_log('ramp.pronta_para_cobranca_inicial', [
                        'account_id'  => $subscription->account_id,
                        'entity_type' => 'subscription',
                        'entity_id'   => $subscription->id,
                        'metadata'    => ['de' => (int) $percentGravado, 'para' => $percentAtual],
                    ]);
                    $resumo['acao_manual']++;
                    CLI::error("  conta {$subscription->account_id}: {$percentGravado}% -> {$percentAtual}% — requer criacao manual da assinatura no gateway (admin/subscription)");

                    continue;
                }

                $plan = $planModel->find($subscription->plan_id);
                if (!$plan) {
                    throw new \RuntimeException('plano nao encontrado');
                }

                $billingCycle = (string) ($subscription->billing_cycle ?? 'MONTHLY');
                $newAmount    = $rampService->amountFor($plan, $billingCycle, $subscription);

                $ok = (new PaymentService())->updateSubscriptionAmount((int) $subscription->id, $newAmount, $percentAtual);

                if (!$ok) {
                    throw new \RuntimeException('gateway recusou a atualizacao de valor');
                }

                audit_log('ramp.valor_atualizado', [
                    'account_id'  => $subscription->account_id,
                    'entity_type' => 'subscription',
                    'entity_id'   => $subscription->id,
                    'metadata'    => ['de' => (int) $percentGravado, 'para' => $percentAtual, 'novo_valor' => $newAmount],
                ]);
                $resumo['atualizado']++;
                CLI::write(sprintf('  conta %d: %d%% -> %d%% — valor atualizado para R$ %.2f', $subscription->account_id, $percentGravado, $percentAtual, $newAmount), 'green');
            } catch (\Throwable $e) {
                $resumo['erro']++;
                CLI::error("  assinatura {$subscription->id}: erro — " . $e->getMessage());
            }
        }

        CLI::write('Concluido: ' . json_encode($resumo), 'green');
    }
}
