<?php

namespace App\Commands;

use App\Models\AccountModel;
use App\Models\LeadChargeRuleModel;
use App\Models\LeadModel;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use App\Services\LaunchRampService;
use App\Services\PaymentService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Migra as contas que ainda estão nos planos antigos (`PlanSeeder` já
 * renomeou os planos de preço antigo para `<CHAVE>_LEGADO`, `ativo=false` —
 * ver `app/Database/Seeds/PlanSeeder.php::renomearLegados()`) para os planos
 * comerciais novos (`PRATA`/`OURO`/`DIAMANTE`).
 *
 * Operação humana com ferramenta, não migration automática — o relatório é
 * o produto principal: por conta, plano atual, preço atual, preço novo (nos
 * dois modos possíveis, lado a lado) e quantos leads a conta recebeu nos
 * últimos 30 dias, a projeção do que ela passaria a pagar de cobrança por
 * lead. Só decide de olho nesse número.
 *
 * `--modo rampa`: a conta ganha `ramp_started_at = hoje` — 6 meses grátis de
 * mensalidade, como um cliente novo. Zera a receita recorrente atual da
 * conta pelo período; se ela já tinha assinatura real no gateway, essa
 * assinatura é CANCELADA (Asaas não aceita assinatura de valor zero — mesma
 * regra da Fase 6 para conta nova) e `assinaturas:aplicar-rampa` recria
 * quando a rampa passar de 0%.
 * `--modo cheio`: a conta vai direto para o preço novo, sem rampa. Se já
 * tinha assinatura real, só o VALOR é corrigido — nada de cancelar.
 *
 * A escolha entre os dois modos é decisão de caixa do cliente
 * (contas antigas ganharem 6 meses grátis zera a recorrência atual; ir
 * direto ao preço cheio evita isso mas cria a situação de o cliente antigo
 * pagar mais que um cliente novo nos primeiros 6 meses) — por isso não tem
 * default: quem roda `--confirmar` escolhe explicitamente.
 *
 * "Comunicação" (e-mail ao cliente avisando da mudança) fica de fora do
 * escopo deste comando — não há infraestrutura de e-mail transacional para
 * isso neste repositório; é passo operacional do time comercial, feito por
 * fora, antes de rodar `--confirmar` (ver runbook em
 * GUIA_ESCALABILIDADE_PRODUCAO.md).
 */
class MigrateCommercialPlans extends BaseCommand
{
    protected $group       = 'Assinaturas';
    protected $name        = 'planos:migrar-comercial';
    protected $description = 'Migra contas de planos legados (_LEGADO) para os planos comerciais novos.';
    protected $usage       = 'planos:migrar-comercial (--dry-run | --confirmar --modo rampa|cheio) [--conta ID]';
    protected $options     = [
        '--dry-run'   => 'Nao grava nada, so lista as contas a migrar com a projecao dos dois modos.',
        '--confirmar' => 'Aplica a migracao. Exige --modo.',
        '--modo'      => 'rampa (6 meses gratis, como conta nova) ou cheio (preco novo desde o dia 1).',
        '--conta'     => 'Restringe a uma unica conta (id). Recomendado na primeira execucao real.',
    ];

    public function run(array $params)
    {
        $dryRun    = CLI::getOption('dry-run') !== null || array_key_exists('dry-run', $params);
        $confirmar = CLI::getOption('confirmar') !== null || array_key_exists('confirmar', $params);
        $modo      = CLI::getOption('modo') ?? ($params['modo'] ?? null);
        $contaId   = CLI::getOption('conta') ?? ($params['conta'] ?? null);

        if ($dryRun === $confirmar) {
            CLI::error('Escolha exatamente um: --dry-run ou --confirmar.');

            return;
        }

        if ($confirmar && ! in_array($modo, ['rampa', 'cheio'], true)) {
            CLI::error('--confirmar exige --modo rampa ou --modo cheio.');

            return;
        }

        $subscriptionModel = model(SubscriptionModel::class);
        $planModel         = model(PlanModel::class);

        $legados = $planModel->like('chave', '_LEGADO', 'before')->findAll();
        if ($legados === []) {
            CLI::write('Nenhum plano legado encontrado. Nada a migrar.', 'green');

            return;
        }

        $legadoIds = array_map(static fn ($p) => $p->id, $legados);

        $query = $subscriptionModel->whereIn('plan_id', $legadoIds)->where('status', 'ACTIVE');
        if ($contaId !== null) {
            $query->where('account_id', (int) $contaId);
        }
        $subscriptions = $query->findAll();

        if ($subscriptions === []) {
            CLI::write('Nenhuma conta em plano legado para migrar.', 'green');

            return;
        }

        if ($dryRun) {
            $this->printReport($subscriptions, $legados, $planModel);

            return;
        }

        $this->applyMigration($subscriptions, $legados, $planModel, $modo);
    }

    /**
     * @param array<int,object> $legados
     * @return array<int,object> plan_id => plano legado
     */
    private function indexById(array $legados): array
    {
        $byId = [];
        foreach ($legados as $legado) {
            $byId[$legado->id] = $legado;
        }

        return $byId;
    }

    private function novoPlanoPara(object $legado, PlanModel $planModel): ?object
    {
        $chaveNova = preg_replace('/_LEGADO$/', '', $legado->chave);

        return $planModel->where('chave', $chaveNova)->first();
    }

    /**
     * Projeção estimada de cobrança de lead dos últimos 30 dias: conta os
     * leads por tipo_negocio e aplica a MESMA regra que cobraria (uma
     * resolução por tipo_negocio, não por lead — a regra de uma conta é
     * praticamente sempre a mesma para todo lead orgânico dela; suficiente
     * para a ordem de grandeza que decide entre os dois modos).
     */
    private function projecaoLeads(int $accountId): array
    {
        $leadModel = model(LeadModel::class);
        $ruleModel = model(LeadChargeRuleModel::class);

        $de = date('Y-m-d 00:00:00', strtotime('-30 days'));
        $porTipo = $leadModel
            ->select('tipo_negocio, COUNT(*) as total')
            ->where('account_id_anunciante', $accountId)
            ->where('created_at >=', $de)
            ->groupBy('tipo_negocio')
            ->findAll();

        $totalLeads = 0;
        $projecao   = 0.0;

        foreach ($porTipo as $row) {
            $tipo = $row->tipo_negocio === 'VENDA_ALUGUEL' ? 'ALUGUEL' : ($row->tipo_negocio ?: null);
            $qtd  = (int) $row->total;
            $totalLeads += $qtd;

            $rule = $ruleModel->resolveFor($accountId, null, $tipo);
            if ($rule !== null) {
                $projecao += $rule->calculate(0.0) * $qtd;
            }
        }

        return ['leads' => $totalLeads, 'projecao' => $projecao];
    }

    private function printReport(array $subscriptions, array $legados, PlanModel $planModel): void
    {
        $legadosPorId   = $this->indexById($legados);
        $accountModel   = model(AccountModel::class);
        $paymentService = new PaymentService();

        CLI::write('Migracao comercial — contas em plano legado (dry-run)', 'yellow');

        $rows = [];
        foreach ($subscriptions as $sub) {
            $legado = $legadosPorId[$sub->plan_id] ?? null;
            $novo   = $legado ? $this->novoPlanoPara($legado, $planModel) : null;
            if (! $legado || ! $novo) {
                continue;
            }

            $account      = $accountModel->find($sub->account_id);
            $billingCycle = (string) ($sub->billing_cycle ?? 'MONTHLY');
            $precoAtual   = $paymentService->getPlanAmountForBillingCycle($legado, $billingCycle);
            $precoCheio   = $paymentService->getPlanAmountForBillingCycle($novo, $billingCycle);
            $lead         = $this->projecaoLeads((int) $sub->account_id);

            $rows[] = [
                $sub->account_id,
                $account->nome ?? '—',
                $legado->chave,
                $novo->chave,
                number_format($precoAtual, 2, ',', '.'),
                'R$ 0,00 (6 meses)',
                number_format($precoCheio, 2, ',', '.'),
                $lead['leads'],
                number_format($lead['projecao'], 2, ',', '.'),
            ];
        }

        CLI::table($rows, ['Conta', 'Nome', 'Plano atual', 'Plano novo', 'Preco atual', 'Novo (rampa)', 'Novo (cheio)', 'Leads 30d', 'Cobranca lead 30d (proj.)']);
        CLI::write(count($rows) . ' conta(s) a migrar. Rode com --confirmar --modo rampa|cheio para aplicar.', 'yellow');
    }

    private function applyMigration(array $subscriptions, array $legados, PlanModel $planModel, string $modo): void
    {
        $legadosPorId      = $this->indexById($legados);
        $subscriptionModel = model(SubscriptionModel::class);
        $rampService       = new LaunchRampService();
        $paymentService    = new PaymentService();
        $resumo            = ['migrada' => 0, 'sem_plano_novo' => 0, 'erro' => 0];

        foreach ($subscriptions as $sub) {
            try {
                $legado = $legadosPorId[$sub->plan_id] ?? null;
                $novo   = $legado ? $this->novoPlanoPara($legado, $planModel) : null;

                if (! $legado || ! $novo) {
                    $resumo['sem_plano_novo']++;
                    CLI::error("  conta {$sub->account_id}: plano novo correspondente nao encontrado — pulada");

                    continue;
                }

                $billingCycle = (string) ($sub->billing_cycle ?? 'MONTHLY');
                $temGateway   = ! empty($sub->asaas_subscription_id);

                if ($modo === 'rampa') {
                    if ($temGateway) {
                        $gateway = $paymentService->getActiveGateway();
                        if (! $gateway || ! $gateway->cancelSubscription($sub->asaas_subscription_id)) {
                            throw new \RuntimeException('nao foi possivel cancelar a assinatura atual no gateway');
                        }
                    }

                    $subscriptionModel->update($sub->id, [
                        'plan_id'            => $novo->id,
                        'ramp_started_at'    => date('Y-m-d'),
                        'ramp_percent_atual' => 0,
                        'valor'              => 0.00,
                        'payment_method'     => 'FREE',
                        // NULL, nunca a data_fim antiga: essa assinatura vem
                        // de uma conta paga (data_fim = fim do ciclo já
                        // contratado), e SubscriptionCheck expira quem
                        // passar de data_fim — sem zerar, a conta migrada
                        // pra rampa perderia o painel no fim do ciclo que já
                        // tinha pago, mesmo com a mensalidade agora em R$0.
                        'data_fim'              => null,
                        'asaas_subscription_id' => $temGateway ? null : $sub->asaas_subscription_id,
                    ]);

                    CLI::write("  conta {$sub->account_id}: {$legado->chave} -> {$novo->chave}, rampa iniciada hoje (0%)" . ($temGateway ? ' — assinatura anterior cancelada no gateway' : ''), 'green');
                } else {
                    $subscriptionModel->update($sub->id, ['plan_id' => $novo->id]);
                    $atualizada = $subscriptionModel->find($sub->id);
                    $novoValor  = $rampService->amountFor($novo, $billingCycle, $atualizada);

                    if ($temGateway) {
                        $paymentService->updateSubscriptionAmount((int) $sub->id, $novoValor, 100);
                    } else {
                        $subscriptionModel->update($sub->id, ['valor' => $novoValor]);
                    }

                    CLI::write(sprintf('  conta %d: %s -> %s, preco cheio R$ %.2f', $sub->account_id, $legado->chave, $novo->chave, $novoValor), 'green');
                }

                audit_log('plano.migrado_comercial', [
                    'account_id'  => $sub->account_id,
                    'entity_type' => 'subscription',
                    'entity_id'   => $sub->id,
                    'metadata'    => ['de' => $legado->chave, 'para' => $novo->chave, 'modo' => $modo],
                ]);
                $resumo['migrada']++;
            } catch (\Throwable $e) {
                $resumo['erro']++;
                CLI::error("  conta {$sub->account_id}: erro — " . $e->getMessage());
            }
        }

        CLI::write('Concluido: ' . json_encode($resumo), 'green');
    }
}
