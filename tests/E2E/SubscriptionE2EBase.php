<?php

namespace Tests\E2E;

use CodeIgniter\Test\CIDatabaseTestCase;
use Tests\Fixtures\SubscriptionTestData;

/**
 * SubscriptionE2EBase - Base class para testes E2E de subscriptions
 * 
 * Fornece helpers comuns para:
 * - Criar contas de teste com Shield users
 * - Upload e verificação de documentos KYC
 * - Criação de subscriptions via Asaas
 * - Simulação de webhooks
 * - Verificação de acesso
 */
abstract class SubscriptionE2EBase extends CIDatabaseTestCase
{
    protected $migrate = false;
    protected $seeders = [];
    protected $testPersona = [];
    protected $testAccountId = null;
    protected $testUserId = null;
    protected $asaasClient = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeAsaasClient();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Limpeza pós-teste (opcional)
    }

    // ================== HELPER METHODS ==================

    /**
     * Criar uma conta de teste completa com usuário Shield
     * 
     * @param string $personaKey Chave da persona (persona_1_initial, etc)
     * @return array [accountId, userId, account, user]
     */
    protected function createTestAccount(string $personaKey): array
    {
        $this->testPersona = SubscriptionTestData::getTestPersonas()[$personaKey]
            ?? throw new \Exception("Persona {$personaKey} não encontrada");

        // 1. Criar conta
        $accountModel = model('App\Models\AccountModel');
        $accountData = [
            'nome' => $this->testPersona['name'],
            'documento' => $this->testPersona['cpf'],
            'email' => $this->testPersona['email'],
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
            'username' => $this->testPersona['email'],
            'email' => $this->testPersona['email'],
            'password' => password_hash('TestPassword123!', PASSWORD_BCRYPT),
            'account_id' => $this->testAccountId,
            'active' => true,
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
        $kycService = service('kyc');
        
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
                return [
                    'success' => false,
                    'message' => 'Falha na verificação facial: ' . $livenessResult['message'],
                    'kycData' => []
                ];
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
     * Criar subscription via Asaas API
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

        // Criar subscription no DB
        $subscriptionData = [
            'account_id' => $accountId,
            'plan_id' => $planId,
            'status' => 'ACTIVE',
            'billing_cycle' => $billingCycle,
            'data_inicio' => date('Y-m-d'),
            'data_fim' => $endDate,
            'proximo_pagamento' => $endDate,
            // asaas_subscription_id seria preenchido pelo webhook
        ];

        $subscriptionId = $subscriptionModel->insert($subscriptionData);

        return [
            'subscriptionId' => $subscriptionId,
            'asaasSubscriptionId' => null, // Seria setado via webhook
            'data' => $subscriptionModel->find($subscriptionId)
        ];
    }

    /**
     * Simular webhook de pagamento do Asaas
     * 
     * @param string $eventType 'PAYMENT_CONFIRMED', 'PAYMENT_FAILED', etc
     * @param array $paymentData
     * @return array [$statusCode, $responseBody]
     */
    protected function simulateAsaasWebhook(string $eventType, array $paymentData): array
    {
        $webhookData = [
            'event' => $eventType,
            'data' => array_merge([
                'id' => 'pay_' . time(),
                'billingType' => 'CREDIT_CARD',
                'status' => 'CONFIRMED', // ou PENDING, RECEIVED, FAILED, etc
                'value' => 199.90,
                'netValue' => 185.00,
                'grossValue' => 199.90,
                'dueDate' => date('Y-m-d'),
                'originalDueDate' => date('Y-m-d'),
                'paymentDate' => date('Y-m-d'),
                'clientPaymentDate' => date('Y-m-d H:i:s'),
                'installmentNumber' => 1,
                'subscription' => null,
                'externalReference' => null,
            ], $paymentData),
            'timestamp' => date('Y-m-d\TH:i:s.000\Z'),
        ];

        // Fazer POST para webhook endpoint
        $webhookUrl = site_url('api/v1/webhooks/asaas');
        
        $client = \Config\Services::curlrequest();
        $response = $client->post($webhookUrl, [
            'json' => $webhookData,
            'headers' => [
                'X-Webhook-Secret' => env('ASAAS_WEBHOOK_TOKEN', 'test'),
            ]
        ]);

        return [
            'statusCode' => $response->getStatusCode(),
            'body' => $response->getBody(),
            'json' => json_decode($response->getBody(), true)
        ];
    }

    /**
     * Verificar acesso de usuário a rota protegida
     * 
     * @param int $userId
     * @param string $route
     * @return array [canAccess, statusCode, message]
     */
    protected function checkUserAccess(int $userId, string $route = '/admin/dashboard'): array
    {
        // Simular login
        auth()->login(auth()->provider()->findById($userId));

        // Tentar acessar rota
        $result = $this->get($route);

        $canAccess = $result->getStatusCode() === 200;
        $message = $canAccess ? 'Acesso permitido' : 'Acesso bloqueado (Status: ' . $result->getStatusCode() . ')';

        auth()->logout();

        return [
            'canAccess' => $canAccess,
            'statusCode' => $result->getStatusCode(),
            'message' => $message,
        ];
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

    // ================== PRIVATE METHODS ==================

    private function initializeAsaasClient(): void
    {
        $config = SubscriptionTestData::getAsaasConfig();
        // TODO: Inicializar cliente HTTP para Asaas se necessário
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
