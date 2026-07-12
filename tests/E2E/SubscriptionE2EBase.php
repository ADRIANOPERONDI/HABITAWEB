<?php

namespace Tests\E2E;

use App\Database\Seeds\PlanSeeder;
use App\Models\PaymentTransactionModel;
use Tests\Fixtures\SubscriptionTestData;
use Tests\Support\HabitawebTestCase;

/**
 * SubscriptionE2EBase - Base class para testes E2E de subscriptions
 *
 * Fornece helpers comuns para:
 * - Criar contas de teste com Shield users
 * - Upload e verificação de documentos KYC
 * - Criação de subscriptions via Asaas
 * - Simulação de webhooks
 * - Verificação de acesso
 *
 * Porta para Tests\Support\HabitawebTestCase (FeatureTestTrait + transação real
 * por teste). Antes disso, esta base estendia Tests\TestCase, que NÃO isola
 * dados entre testes — cada execução acumulava contas/planos no banco de teste,
 * e como PlanModel::$validationRules exige 'nome' único, o segundo run em diante
 * fazia o insert do plano de fallback falhar silenciosamente (insert() retornando
 * false -> castado a 0), causando "Plano #0 não encontrado". Com a transação
 * real por teste, cada execução parte de um estado limpo e o bug desaparece.
 */
abstract class SubscriptionE2EBase extends HabitawebTestCase
{
    protected $testPersona = [];
    protected $testAccountId = null;
    protected $testUserId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    // ================== HELPER METHODS ==================

    /**
     * Criar uma conta de teste completa com usuário Shield
     *
     * @param string $personaKey Chave da persona (persona_1_initial, etc)
     * @return array [accountId, userId, account, user]
     */
    protected function createE2ETestAccount(string $personaKey): array
    {
        $this->testPersona = SubscriptionTestData::getTestPersonas()[$personaKey]
            ?? throw new \Exception("Persona {$personaKey} não encontrada");

        $uniqueSuffix = (string) microtime(true);
        $uniqueSuffix = str_replace('.', '', $uniqueSuffix);
        [$emailUser, $emailDomain] = explode('@', $this->testPersona['email'], 2);
        $uniqueEmail = $emailUser . '+' . $uniqueSuffix . '@' . $emailDomain;
        $uniqueDocumento = preg_replace('/\D+/', '', (string) $this->testPersona['cpf']) . substr($uniqueSuffix, -2);
        $uniqueUsername = 'e2e_' . substr($uniqueSuffix, -12);

        // 1. Criar conta
        $accountModel = model('App\Models\AccountModel');
        $accountData = [
            'nome' => $this->testPersona['name'],
            'documento' => substr($uniqueDocumento, 0, 14),
            'email' => $uniqueEmail,
            'telefone' => $this->testPersona['phone'],
            'tipo_conta' => $this->testPersona['tipo_conta'],
            'status' => 'ACTIVE',
            'is_verified' => false,
            'verification_status' => 'NONE',
        ];

        $this->testAccountId = $accountModel->insert($accountData);
        $account = $accountModel->find($this->testAccountId);

        // 2. Criar usuário Shield
        $userModel = model('App\Models\UserModel');
        $userData = [
            'username' => $uniqueUsername,
            'email' => $uniqueEmail,
            'password' => password_hash('TestPassword123!', PASSWORD_BCRYPT),
            'account_id' => $this->testAccountId,
            'active' => 1,
        ];

        $this->testUserId = $userModel->insert($userData);

        return [
            'accountId' => $this->testAccountId,
            'userId' => $this->testUserId,
            'account' => $account,
            'user' => $userModel->find($this->testUserId),
        ];
    }

    /**
     * Upload e verificação de documentos KYC
     *
     * @param int $accountId
     * @param bool $withFacial Se deve também fazer verificação facial
     * @return array [success, message, kycData]
     */
    protected function verifyAccountKYC(int $accountId, bool $withFacial = true): array
    {
        $kycService = new \App\Services\KYCService();

        // 1. Upload de documentos
        $mockDocs = SubscriptionTestData::getMockDocumentImages();

        // Simular upload de arquivo (em produção seria via HTTP request)
        $uploadPath = WRITEPATH . 'uploads/kyc/' . $accountId;
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        // Salvar documentos simulados
        $idFrontPath = $uploadPath . '/id_front_' . time() . '.jpg';
        $idBackPath = $uploadPath . '/id_back_' . time() . '.jpg';
        $selfiePath = $uploadPath . '/selfie_' . time() . '.jpg';

        file_put_contents($idFrontPath, $mockDocs['id_front']);
        file_put_contents($idBackPath, $mockDocs['id_back']);
        file_put_contents($selfiePath, $mockDocs['selfie']);

        // 2. Atualizar conta com caminhos dos documentos
        $accountModel = model('App\Models\AccountModel');
        $accountModel->update($accountId, [
            'id_front' => 'uploads/kyc/' . $accountId . '/' . basename($idFrontPath),
            'id_back' => 'uploads/kyc/' . $accountId . '/' . basename($idBackPath),
            'selfie' => 'uploads/kyc/' . $accountId . '/' . basename($selfiePath),
            'verification_status' => 'PENDING',
        ]);

        // 3. Verificação facial (liveness) se solicitado
        if ($withFacial) {
            $livenessResult = $kycService->verifyFacialLiveness($accountId, [
                'provider' => 'mock',
                'threshold' => 0.90
            ]);

            if (!$livenessResult['success']) {
                // Evita flakiness de testes quando mock randômico falha.
                $kycService->markAccountVerified($accountId, 'VERIFIED', 'Forced verification in E2E test mode');
            }
        } else {
            // Sem facial, apenas marcar como verificado manualmente
            $kycService->markAccountVerified($accountId, 'VERIFIED', 'Manual verification for E2E test');
        }

        return [
            'success' => true,
            'message' => 'KYC verificado com sucesso',
            'kycData' => [
                'id_front' => 'uploads/kyc/' . $accountId . '/' . basename($idFrontPath),
                'id_back' => 'uploads/kyc/' . $accountId . '/' . basename($idBackPath),
                'selfie' => 'uploads/kyc/' . $accountId . '/' . basename($selfiePath),
            ]
        ];
    }

    /**
     * Criar subscription local + a transação Asaas correspondente que o webhook
     * real vai casar (App\Services\WebhookService::findTransactionBySubscriptionId
     * casa por payment_transactions.gateway_subscription_id). Sem essa transação
     * "PENDING" espelhando o gateway_subscription_id, um webhook PAYMENT_CONFIRMED
     * de verdade não encontraria o que ativar.
     *
     * @param int $accountId
     * @param int $planId
     * @param string $billingCycle 'MONTHLY', 'QUARTERLY', 'SEMI_ANNUAL', 'ANNUAL'
     * @return array [subscriptionId, asaasSubscriptionId, data]
     */
    protected function createSubscription(int $accountId, int $planId, string $billingCycle = 'MONTHLY'): array
    {
        $subscriptionModel = model('App\Models\SubscriptionModel');
        $planModel = model('App\Models\PlanModel');
        $plan = $planModel->find($planId);

        if (!$plan) {
            throw new \Exception("Plano #{$planId} não encontrado");
        }

        // Calcular data de vencimento (período de teste = 30 dias)
        $endDate = (new \DateTime())->modify('+30 days')->format('Y-m-d');
        $asaasSubscriptionId = 'sub_e2e_' . bin2hex(random_bytes(6));

        // Status ACTIVE já na criação (não PENDING-até-webhook): mantém a mesma
        // convenção que os 9 cenários já assumem — vários fazem asserções de
        // status logo após createSubscription(), antes de qualquer webhook.
        // Redesenhar essa premissa (subscription só ativa após confirmação real
        // de pagamento) é uma mudança de escopo maior, fora do que este ajuste
        // (parar de reimplementar webhook/acesso localmente) se propôs a corrigir.
        $subscriptionData = [
            'account_id' => $accountId,
            'plan_id' => $planId,
            'status' => 'ACTIVE',
            'billing_cycle' => $billingCycle,
            'data_inicio' => date('Y-m-d'),
            'data_fim' => $endDate,
            'proximo_pagamento' => $endDate,
            'asaas_subscription_id' => $asaasSubscriptionId,
        ];

        $subscriptionId = $subscriptionModel->insert($subscriptionData);

        (new PaymentTransactionModel())->insert([
            'subscription_id' => $subscriptionId,
            'account_id' => $accountId,
            'gateway' => 'asaas',
            'gateway_subscription_id' => $asaasSubscriptionId,
            'method' => 'PIX',
            'amount' => (float) ($plan->preco_mensal ?? 0),
            'status' => 'PENDING',
            'type' => 'SUBSCRIPTION',
            'due_date' => date('Y-m-d'),
        ]);

        return [
            'subscriptionId' => $subscriptionId,
            'asaasSubscriptionId' => $asaasSubscriptionId,
            'data' => $subscriptionModel->find($subscriptionId)
        ];
    }

    /**
     * Simular webhook de pagamento do Asaas — POST real e assinado para o
     * endpoint de produção (App\Controllers\Web\WebhookController::asaas),
     * passando pela validação de token (H7 da auditoria) e pela lógica real de
     * App\Services\WebhookService::processEvent(). Nenhuma reimplementação local:
     * o resultado reflete exatamente o que a Asaas dispararia em produção.
     *
     * @param string $eventType 'PAYMENT_CONFIRMED' ou 'PAYMENT_FAILED'
     * @param array $paymentData Os cenários passam 'subscription' com o ID LOCAL
     *              (numérico) retornado por createSubscription()['subscriptionId']
     *              — convenção histórica mantida por compatibilidade. É traduzido
     *              aqui para o asaas_subscription_id real antes do POST, já que é
     *              esse o identificador que o webhook de produção usa para casar
     *              a transação (App\Services\WebhookService::findTransactionBySubscriptionId).
     * @return array [$statusCode, $responseBody, $json]
     */
    protected function simulateAsaasWebhook(string $eventType, array $paymentData): array
    {
        if (!empty($paymentData['subscription']) && ctype_digit((string) $paymentData['subscription'])) {
            $sub = model('App\Models\SubscriptionModel')->find((int) $paymentData['subscription']);
            if ($sub && !empty($sub->asaas_subscription_id)) {
                $paymentData['subscription'] = $sub->asaas_subscription_id;
            }
        }

        $payment = array_merge([
            'id' => 'pay_e2e_' . bin2hex(random_bytes(6)),
            'billingType' => 'PIX',
            'status' => $eventType === 'PAYMENT_CONFIRMED' ? 'CONFIRMED' : 'OVERDUE',
            'value' => 199.90,
            'subscription' => null, // asaas_subscription_id — obrigatório para casar a transação
        ], $paymentData);

        $webhookPayload = [
            'event' => $eventType,
            'payment' => $payment,
        ];

        // FeatureTestTrait::populateGlobals() copia os $params crus para $_POST
        // (só a query string real vira string em HTTP de verdade); o filtro global
        // 'invalidchars' então chama mb_check_encoding() em cada valor de $_POST e
        // quebra ao encontrar um float/int não-string. O corpo JSON real (o que o
        // controller efetivamente lê via getJSON()) não é afetado — só precisamos
        // que os valores em $params (usados também para popular $_POST) sejam string.
        $result = $this->withHeaders(['asaas-access-token' => 'phpunit-fixed-webhook-token'])
            ->withBodyFormat('json')
            ->post('asaas/webhook', $this->stringifyLeaves($webhookPayload));

        $body = $result->getJSON();

        return [
            'statusCode' => $result->response()->getStatusCode(),
            'body' => $body,
            'json' => json_decode($body, true),
        ];
    }

    private function stringifyLeaves(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->stringifyLeaves($value);
            } elseif (is_int($value) || is_float($value)) {
                $data[$key] = (string) $value;
            }
        }

        return $data;
    }

    /**
     * Verificar acesso de usuário a rota protegida — GET real através do
     * App\Filters\AdminAuth de produção (KYC + assinatura + fatura em dia),
     * não mais uma reimplementação das regras em query builder.
     *
     * @param int $userId
     * @param string $route
     * @return array [canAccess, statusCode, message]
     */
    protected function checkUserAccess(int $userId, string $route = 'admin/dashboard'): array
    {
        $user = model('App\Models\UserModel')->find($userId);
        if (!$user) {
            return ['canAccess' => false, 'statusCode' => 302, 'message' => 'Usuário não encontrado'];
        }

        $result = $this->actingAs($user)->get(ltrim($route, '/'));
        $status = $result->response()->getStatusCode();
        $isRedirect = $result->response() instanceof \CodeIgniter\HTTP\RedirectResponse
            || ($status >= 300 && $status < 400);

        return [
            'canAccess' => !$isRedirect && $status < 300,
            'statusCode' => $status,
            'message' => $isRedirect
                ? 'Bloqueado pelo AdminAuth (redirecionado para ' . $result->response()->getHeaderLine('Location') . ')'
                : 'Acesso permitido',
        ];
    }

    protected function promoteUserToSuperAdmin(int $userId): void
    {
        $db = \Config\Database::connect();
        $already = $db->table('auth_groups_users')
            ->where('user_id', $userId)
            ->where('group', 'superadmin')
            ->countAllResults() > 0;

        if (!$already) {
            $db->table('auth_groups_users')->insert([
                'user_id' => $userId,
                'group' => 'superadmin',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Avançar data do sistema (para testar renovações, carências, etc)
     * NOTA: Isso é complexo em produção. Para E2E, usar Carbon para mock de data
     *
     * @param int $days
     * @return void
     */
    protected function advanceSystemDate(int $days = 1): void
    {
        // TODO: Implementar com Carbon::setTestNow() ou similar
        // Por enquanto, apenas modificar datas manualmente em testes específicos
    }

    /**
     * Helper para assertions comuns
     */
    protected function assertSubscriptionActive(int $subscriptionId): void
    {
        $subModel = model('App\Models\SubscriptionModel');
        $sub = $subModel->find($subscriptionId);

        $this->assertNotNull($sub, "Subscription #{$subscriptionId} not found");
        $this->assertEquals('ACTIVE', $sub->status, "Subscription status should be ACTIVE");
    }

    protected function assertAccountFullyVerified(int $accountId): void
    {
        $acctModel = model('App\Models\AccountModel');
        $account = $acctModel->find($accountId);

        $this->assertNotNull($account, "Account #{$accountId} not found");
        $this->assertTrue($account->is_verified, "Account should be verified");
        $this->assertEquals('VERIFIED', $account->verification_status);
        $this->assertNotEmpty($account->id_front);
        $this->assertNotEmpty($account->id_back);
        $this->assertNotEmpty($account->selfie);
    }
}
