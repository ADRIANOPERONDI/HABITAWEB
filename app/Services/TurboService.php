<?php

namespace App\Services;

use App\Models\PromotionModel;
use App\Models\PromotionPackageModel;
use App\Models\PropertyModel;
use App\Models\SubscriptionModel;
use CodeIgniter\Config\Factories;

/**
 * Dono do domínio "turbinada": cota mensal incluída no plano, compra avulsa e
 * concessão manual, todas convergindo no mesmo mecanismo de ativação.
 *
 * `PromotionService` fica só com a ponte de pagamento (listar pacotes, gerar a
 * cobrança no Asaas). Este service decide QUEM pode turbinar, QUANTO já usou
 * este mês, e É QUEM efetivamente grava `promotions` + `properties.highlight_*`
 * — nos três caminhos, pelo mesmo `activate()` privado.
 */
class TurboService
{
    /** Origem de uma linha de `promotions`. */
    public const ORIGEM_PLANO    = 'PLANO';
    public const ORIGEM_PAGO     = 'PAGO';
    public const ORIGEM_CORTESIA = 'CORTESIA';

    protected PromotionModel $promotionModel;
    protected PromotionPackageModel $packageModel;
    protected PropertyModel $propertyModel;
    protected SubscriptionModel $subscriptionModel;

    public function __construct()
    {
        $this->promotionModel    = Factories::models(PromotionModel::class);
        $this->packageModel      = Factories::models(PromotionPackageModel::class);
        $this->propertyModel     = Factories::models(PropertyModel::class);
        $this->subscriptionModel = Factories::models(SubscriptionModel::class);
    }

    /**
     * Quantas turbinadas a conta tem, já usou e ainda pode usar este mês.
     *
     * `incluidas === null` significa ilimitado (plano com `limite_turbo_mensal`
     * NULL). Conta sem assinatura vigente não tem cota nenhuma — `incluidas = 0`,
     * não null, porque "sem plano" não é "ilimitado".
     *
     * Assinatura no ciclo ANUAL soma `turbo_bonus_anual` à cota do plano — é o
     * benefício do anual descrito na proposta comercial (exposição, não
     * desconto): Prata anual +2/mês, Ouro +3/mês, Diamante +5/mês. O bônus entra
     * na MESMA contagem por `periodo` da cota normal — não é um contador à
     * parte, então reseta junto com ela todo mês, sem mecanismo novo.
     *
     * @return array{incluidas: ?int, usadas: int, restantes: ?int, periodo: string}
     */
    public function quotaFor(int $accountId): array
    {
        $periodo = $this->periodoAtual();
        $plan    = PlanGate::for($accountId);

        // `$plan?->turbosIncluidos() ?? 0` pareceria certo e é o bug clássico
        // desta função: turbosIncluidos() já devolve null para "ilimitado", e
        // `?? 0` engoliria esse null junto com o de "conta sem plano" — os dois
        // casos têm significado oposto. Só "sem plano" vira 0.
        $incluidas = $plan === null ? 0 : $plan->turbosIncluidos();

        // Bônus do anual só faz sentido quando a cota é finita — "ilimitado +
        // bônus" continua ilimitado, e sem plano não há bônus a somar.
        if ($plan !== null && $incluidas !== null && $this->billingCycleFor($accountId) === 'YEARLY') {
            $incluidas += $plan->turboBonusAnual();
        }

        $usadas = $this->promotionModel
            ->where('account_id', $accountId)
            ->where('origem', self::ORIGEM_PLANO)
            ->where('periodo', $periodo)
            ->countAllResults();

        return [
            'incluidas' => $incluidas,
            'usadas'    => $usadas,
            'restantes' => $incluidas === null ? null : max(0, $incluidas - $usadas),
            'periodo'   => $periodo,
        ];
    }

    /**
     * Turbina um imóvel consumindo a cota mensal do plano — custo zero para o
     * tenant, mesmo mecanismo do pacote pago.
     *
     * O advisory lock evita que duas abas clicando "turbinar" ao mesmo tempo
     * dupliquem a leitura de `usadas` antes de qualquer uma delas gravar (a
     * clássica corrida de contador). `hashtext` gera a chave a partir da string;
     * escopada por conta, então contas diferentes nunca disputam o mesmo lock.
     */
    public function activateFromQuota(int $propertyId, int $accountId): array
    {
        $property = $this->propertyModel->find($propertyId);

        if (! $property) {
            return ['success' => false, 'message' => 'Imóvel não encontrado.'];
        }

        if ((int) $property->account_id !== $accountId) {
            return ['success' => false, 'message' => 'Este imóvel não pertence à sua conta.'];
        }

        $package = $this->resolveIncludedPackage();

        if (! $package) {
            return ['success' => false, 'message' => 'Nenhum pacote de turbinada configurado.'];
        }

        $db = \Config\Database::connect();
        $db->transStart();
        $db->query('SELECT pg_advisory_xact_lock(hashtext(?))', ['turbo_quota_' . $accountId]);

        $quota = $this->quotaFor($accountId);

        if ($quota['restantes'] !== null && $quota['restantes'] <= 0) {
            $db->transComplete();

            return [
                'success' => false,
                'message' => "Você já usou as {$quota['incluidas']} turbinadas incluídas no seu plano este mês.",
            ];
        }

        $result = $this->activate($propertyId, $accountId, $package, self::ORIGEM_PLANO, null, (int) $package->id);

        $db->transComplete();

        if (! $db->transStatus()) {
            return ['success' => false, 'message' => 'Não foi possível ativar a turbinada. Tente novamente.'];
        }

        return $result;
    }

    /**
     * Ativa a turbinada comprada, chamado pelo webhook de pagamento confirmado.
     *
     * Idempotente por construção: `$paymentTransactionId` é gravado em
     * `promotions.payment_transaction_id`, coluna com índice UNIQUE parcial. Um
     * webhook repetido para a mesma cobrança encontra a linha já ativada e
     * retorna sucesso sem duplicar nem reprocessar `properties.highlight_*`.
     *
     * Não usa `Model::insert()` para o INSERT em si — uma violação de UNIQUE
     * aborta a transação Postgres inteira (não só a instrução), e isso
     * inviabilizaria a leitura "já existe?" na mesma transação. Por isso o
     * caminho pago faz o INSERT com `ON CONFLICT DO NOTHING` em SQL, que nunca
     * levanta erro.
     */
    public function activatePaid(int $propertyId, string $packageKey, int $paymentTransactionId): bool
    {
        $package = $this->packageModel->where('chave', $packageKey)->first();

        if (! $package || $package->tipo_promocao !== PromotionService::TIPO_TURBO || (int) $package->duracao_dias <= 0) {
            log_message('error', "[Turbo] Pacote inválido para ativação paga: {$packageKey} (imóvel {$propertyId}, transação {$paymentTransactionId}).");

            return false;
        }

        $property = $this->propertyModel->find($propertyId);

        if (! $property) {
            log_message('error', "[Turbo] Imóvel {$propertyId} não encontrado para ativação paga (transação {$paymentTransactionId}).");

            return false;
        }

        $result = $this->activate(
            $propertyId,
            (int) $property->account_id,
            $package,
            self::ORIGEM_PAGO,
            $paymentTransactionId,
            (int) $package->id
        );

        return $result['success'];
    }

    /**
     * Ponto único de gravação. Os três caminhos (cota, pago, cortesia)
     * convergem aqui — é o que garante que a turbinada incluída no plano usa
     * exatamente o mesmo mecanismo da comprada, com custo zero.
     *
     * @return array{success: bool, message: string}
     */
    private function activate(
        int $propertyId,
        ?int $accountId,
        object $package,
        string $origem,
        ?int $paymentTransactionId,
        ?int $promotionPackageId
    ): array {
        $level = $this->levelFor($package->tipo_promocao);

        if ($level === null) {
            log_message('error', sprintf(
                '[Turbo] tipo_promocao sem nivel mapeado: "%s" (pacote %s, imovel %d). Ativacao abortada.',
                $package->tipo_promocao,
                $package->chave,
                $propertyId
            ));

            return ['success' => false, 'message' => 'Pacote de turbinada mal configurado.'];
        }

        $startDate = date('Y-m-d H:i:s');
        $endDate   = date('Y-m-d H:i:s', strtotime("+{$package->duracao_dias} days"));

        $inTransaction = $origem === self::ORIGEM_PLANO; // activateFromQuota já abriu a transação
        $db = \Config\Database::connect();

        if (! $inTransaction) {
            $db->transStart();
        }

        if ($paymentTransactionId !== null) {
            $inserted = $this->insertIdempotente(
                $propertyId,
                $accountId,
                $package,
                $origem,
                $startDate,
                $endDate,
                $paymentTransactionId,
                $promotionPackageId
            );

            if (! $inserted) {
                // ON CONFLICT DO NOTHING: já existe linha para esta cobrança.
                // Replay de webhook — não é erro, e properties.highlight_* já
                // foi gravado na primeira ativação, então não se toca de novo.
                if (! $inTransaction) {
                    $db->transComplete();
                }

                log_message('info', "[Turbo] Ativação para a transação {$paymentTransactionId} já existia — webhook repetido, ignorado.");

                return ['success' => true, 'message' => 'Turbinada já estava ativa.'];
            }
        } else {
            $this->promotionModel->insert([
                'property_id'            => $propertyId,
                'account_id'             => $accountId,
                'tipo_promocao'          => $package->tipo_promocao,
                'origem'                 => $origem,
                'periodo'                => $this->periodoAtual(),
                'data_inicio'            => $startDate,
                'data_fim'               => $endDate,
                'ativo'                  => true,
                'payment_transaction_id' => null,
                'promotion_package_id'   => $promotionPackageId,
            ]);
        }

        $this->propertyModel->update($propertyId, [
            'highlight_level'      => $level,
            'highlight_expires_at' => $endDate,
        ]);

        if (! $inTransaction) {
            $db->transComplete();

            if (! $db->transStatus()) {
                return ['success' => false, 'message' => 'Não foi possível ativar a turbinada. Tente novamente.'];
            }
        }

        return ['success' => true, 'message' => 'Turbinada ativada.'];
    }

    /**
     * INSERT que não aborta a transação em caso de conflito.
     *
     * @return bool true se inseriu uma linha nova; false se já existia (conflito).
     */
    private function insertIdempotente(
        int $propertyId,
        ?int $accountId,
        object $package,
        string $origem,
        string $startDate,
        string $endDate,
        int $paymentTransactionId,
        ?int $promotionPackageId
    ): bool {
        $db = \Config\Database::connect();

        $row = $db->query(
            'INSERT INTO promotions
                (property_id, account_id, tipo_promocao, origem, periodo, data_inicio, data_fim, ativo,
                 payment_transaction_id, promotion_package_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, true, ?, ?, NOW(), NOW())
             ON CONFLICT (payment_transaction_id) WHERE payment_transaction_id IS NOT NULL DO NOTHING
             RETURNING id',
            [
                $propertyId,
                $accountId,
                $package->tipo_promocao,
                $origem,
                $this->periodoAtual(),
                $startDate,
                $endDate,
                $paymentTransactionId,
                $promotionPackageId,
            ]
        )->getRow();

        return $row !== null;
    }

    /**
     * Nível de destaque para cada tipo de promoção.
     *
     * TURBO_IMOVEL é o único tipo realmente vendido hoje. Tipo desconhecido
     * devolve null — quem chama decide como tratar (aqui, aborta e loga; não
     * concede exposição de graça por coincidência de `default`).
     */
    private function levelFor(string $tipoPromocao): ?int
    {
        return match ($tipoPromocao) {
            PromotionService::TIPO_TURBO => 1,
            'DESTAQUE'                   => 1,
            'SUPER_DESTAQUE'              => 2,
            'VITRINE'                     => 3,
            default                       => null,
        };
    }

    /**
     * Pacote usado pela turbinada incluída no plano — a mais barata entre as
     * de exposição com prazo, hoje `TURBO_7_DIAS`. Resolvido por característica
     * em vez de chave fixa: sobrevive a um rename do pacote no admin.
     */
    private function resolveIncludedPackage(): ?object
    {
        return $this->packageModel
            ->where('tipo_promocao', PromotionService::TIPO_TURBO)
            ->where('duracao_dias >', 0)
            ->orderBy('duracao_dias', 'ASC')
            ->first();
    }

    private function periodoAtual(): string
    {
        return date('Y-m-01');
    }

    /**
     * Ciclo de cobrança da assinatura vigente da conta, para decidir o bônus
     * de turbinada do anual. Sem assinatura vigente, trata como mensal (sem
     * bônus) — mesma janela de status que PlanGate usa para "conta tem plano".
     */
    private function billingCycleFor(int $accountId): string
    {
        $subscription = $this->subscriptionModel
            ->where('account_id', $accountId)
            ->whereIn('status', PlanGate::STATUS_VIGENTES)
            ->orderBy('id', 'DESC')
            ->first();

        // strtoupper por robustez: o vocabulário real (CheckoutController /
        // PaymentService::normalizeBillingCycle) é sempre maiúsculo, mas nada
        // impede outro caminho de gravar diferente.
        return strtoupper((string) ($subscription->billing_cycle ?? 'MONTHLY'));
    }

    /**
     * Desativa promoções vencidas (cron `promo:cleanup`).
     *
     * Não é mais a barreira de exposição — `HighlightSql::effectiveLevel()` já
     * trata destaque vencido como nível 0 em toda leitura pública, mesmo que
     * este cron não tenha rodado. Aqui só se mantém `properties.highlight_level`
     * coerente com `promotions.ativo` para quem olha a coluna diretamente.
     */
    public function deactivateExpired(): void
    {
        $expired = $this->promotionModel
            ->where('ativo', true)
            ->where('data_fim <', date('Y-m-d H:i:s'))
            ->findAll();

        if ($expired === []) {
            return;
        }

        $db = \Config\Database::connect();

        foreach ($expired as $promo) {
            $db->transStart();

            $this->promotionModel->update($promo->id, ['ativo' => false]);

            $outraAtiva = $this->promotionModel
                ->where('property_id', $promo->property_id)
                ->where('ativo', true)
                ->where('id !=', $promo->id)
                ->where('data_fim >', date('Y-m-d H:i:s'))
                ->orderBy('data_fim', 'DESC')
                ->first();

            if (! $outraAtiva) {
                $this->propertyModel->update($promo->property_id, [
                    'highlight_level'      => 0,
                    'highlight_expires_at' => null,
                ]);
            }

            $db->transComplete();
        }
    }
}
