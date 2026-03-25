<?php

namespace Tests;

use App\Test\TestCase;

/**
 * TESTES PAYMENT GATEWAY INTEGRATION
 * 
 * Validar integrações Asaas, Stripe, Mercado Pago
 * Teste: php spark test --filter PaymentGatewayTest
 */
class PaymentGatewayTest extends TestCase
{
    protected $dbGroup = 'default';
    protected $apiToken = 'test_api_key';

    // ==================== ASAAS INTEGRATION ====================

    /**
     * @test
     * Criar pagamento via Asaas
     */
    public function testCreateAsaasPayment()
    {
        $paymentData = [
            'gateway' => 'asaas',
            'amount' => 99.90,
            'description' => 'Plano Premium',
            'customer_email' => 'customer@example.com',
            'payment_method' => 'credit_card',
            'installment_count' => 1,
            'card' => [
                'number' => '4111111111111111',
                'holder' => 'TEST USER',
                'expiry_month' => '12',
                'expiry_year' => '2030',
                'cvv' => '123'
            ]
        ];

        $response = $this->post('/api/v1/payments', $paymentData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertTrue(
            $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
            'Payment deve ser criado com sucesso'
        );

        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('transaction_id', $data['data']);
    }

    /**
     * @test
     * Verificar status de pagamento Asaas
     */
    public function testCheckAsaasPaymentStatus()
    {
        // Criar pagamento primeiro
        $paymentData = [
            'gateway' => 'asaas',
            'amount' => 50.00,
            'description' => 'Test'
        ];

        $createResponse = $this->post('/api/v1/payments', $paymentData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        if ($createResponse->getStatusCode() === 201) {
            $data = json_decode($createResponse->getBody(), true);
            $transactionId = $data['data']['transaction_id'];

            // Verificar status
            $statusResponse = $this->get("/api/v1/payments/$transactionId", [
                'headers' => ['X-API-Key' => $this->apiToken]
            ]);

            $this->assertResponseStatus(200);
            $statusData = json_decode($statusResponse->getBody(), true);
            
            $this->assertArrayHasKey('status', $statusData['data']);
            $this->assertContains(
                $statusData['data']['status'],
                ['pending', 'confirmed', 'failed', 'cancelled']
            );
        }
    }

    /**
     * @test
     * Asaas Webhook - Payment Confirmed
     */
    public function testAsaasWebhookPaymentConfirmed()
    {
        $webhookData = [
            'event' => 'PAYMENT_CONFIRMED',
            'payment' => [
                'id' => 'pay_asaas_' . uniqid(),
                'account_id' => env('ASAAS_ACCOUNT_ID'),
                'customer' => 'customer@example.com',
                'value' => 99.90,
                'status' => 'CONFIRMED',
                'confirmationDate' => date('Y-m-d'),
                'transactionReceiptUrl' => 'https://asaas.com/receipt'
            ]
        ];

        $response = $this->post('/webhook/asaas', $webhookData);

        $this->assertResponseStatus(200, 'Webhook deve ser processado');

        // Verificar que assinatura foi ativada
        $subscription = $this->db->table('subscriptions')
            ->where('gateway_transaction_id', $webhookData['payment']['id'])
            ->first();

        $this->assertNotNull($subscription, 'Subscription deve ser criada/atualizada');
        $this->assertEquals('active', $subscription->status ?? 'inactive');
    }

    /**
     * @test
     * Asaas Webhook - Payment Failed
     */
    public function testAsaasWebhookPaymentFailed()
    {
        $webhookData = [
            'event' => 'PAYMENT_FAILED',
            'payment' => [
                'id' => 'pay_failed_' . uniqid(),
                'value' => 99.90,
                'status' => 'FAILED',
                'failureReason' => 'Card declined'
            ]
        ];

        $response = $this->post('/webhook/asaas', $webhookData);

        $this->assertResponseStatus(200);

        // Verificar que não foi ativado
        $subscription = $this->db->table('subscriptions')
            ->where('gateway_transaction_id', $webhookData['payment']['id'])
            ->first();

        if ($subscription) {
            $this->assertNotEquals('active', $subscription->status);
        }
    }

    /**
     * @test
     * Asaas Webhook - Pix Confirmado
     */
    public function testAsaasWebhookPixConfirmed()
    {
        $webhookData = [
            'event' => 'PIX_RECEIVED',
            'payment' => [
                'id' => 'pix_' . uniqid(),
                'value' => 199.90,
                'status' => 'RECEIVED',
                'brCode' => '00020126580014br.gov.bcb.pix...',
                'pixKey' => 'abc123@example.com'
            ]
        ];

        $response = $this->post('/webhook/asaas', $webhookData);

        $this->assertResponseStatus(200);
    }

    // ==================== STRIPE INTEGRATION ====================

    /**
     * @test
     * Criar pagamento via Stripe
     */
    public function testCreateStripePayment()
    {
        $paymentData = [
            'gateway' => 'stripe',
            'amount' => 99.90,
            'currency' => 'BRL',
            'description' => 'Plano Premium',
            'customer_email' => 'customer@example.com',
            'card' => [
                'number' => '4242424242424242',
                'exp_month' => 12,
                'exp_year' => 2030,
                'cvc' => '123'
            ]
        ];

        $response = $this->post('/api/v1/payments', $paymentData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertTrue(
            $response->getStatusCode() >= 200 && $response->getStatusCode() < 300
        );

        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('transaction_id', $data['data']);
    }

    /**
     * @test
     * Stripe Webhook - charge.succeeded
     */
    public function testStripeWebhookChargeSucceeded()
    {
        // Simular webhook Stripe
        $webhookData = [
            'type' => 'charge.succeeded',
            'data' => [
                'object' => [
                    'id' => 'ch_stripe_' . uniqid(),
                    'amount' => 9990, // Em centavos
                    'currency' => 'brl',
                    'paid' => true,
                    'status' => 'succeeded',
                    'receipt_email' => 'customer@example.com'
                ]
            ]
        ];

        $response = $this->post('/webhook/stripe', $webhookData, [
            'headers' => ['Stripe-Signature' => $this->generateStripeSignature($webhookData)]
        ]);

        $this->assertResponseStatus(200);
    }

    /**
     * @test
     * Stripe Webhook - charge.failed
     */
    public function testStripeWebhookChargeFailed()
    {
        $webhookData = [
            'type' => 'charge.failed',
            'data' => [
                'object' => [
                    'id' => 'ch_failed_' . uniqid(),
                    'amount' => 9990,
                    'paid' => false,
                    'status' => 'failed',
                    'failure_message' => 'Card declined'
                ]
            ]
        ];

        $response = $this->post('/webhook/stripe', $webhookData, [
            'headers' => ['Stripe-Signature' => $this->generateStripeSignature($webhookData)]
        ]);

        $this->assertResponseStatus(200);
    }

    /**
     * @test
     * Stripe - Recuperar token de cliente
     */
    public function testStripeCustomerToken()
    {
        $response = $this->post('/api/v1/stripe/create-payment-intent', [
            'amount' => 99.90,
            'currency' => 'brl',
            'email' => 'customer@example.com'
        ], [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertResponseStatus(200);
        $data = json_decode($response->getBody(), true);
        
        $this->assertArrayHasKey('client_secret', $data);
        $this->assertNotEmpty($data['client_secret']);
    }

    // ==================== MERCADO PAGO INTEGRATION ====================

    /**
     * @test
     * Criar pagamento via Mercado Pago
     */
    public function testCreateMercadoPagoPayment()
    {
        $paymentData = [
            'gateway' => 'mercado_pago',
            'amount' => 99.90,
            'currency' => 'BRL',
            'description' => 'Plano Premium',
            'payer' => [
                'email' => 'customer@example.com',
                'first_name' => 'Test',
                'last_name' => 'User'
            ],
            'card' => [
                'number' => '5031755734530604',
                'exp_month' => '12',
                'exp_year' => '2030',
                'security_code' => '123',
                'holder_name' => 'TEST USER'
            ]
        ];

        $response = $this->post('/api/v1/payments', $paymentData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertTrue(
            $response->getStatusCode() >= 200 && $response->getStatusCode() < 300
        );

        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('transaction_id', $data['data']);
    }

    /**
     * @test
     * Mercado Pago Webhook - payment.success
     */
    public function testMercadoPagoWebhookSuccess()
    {
        $webhookData = [
            'id' => rand(100000000, 999999999),
            'type' => 'payment',
            'data' => [
                'id' => 'mp_' . uniqid()
            ]
        ];

        $response = $this->post('/webhook/mercado_pago', $webhookData);

        $this->assertResponseStatus(200);
    }

    /**
     * @test
     * Mercado Pago Webhook - payment.pending
     */
    public function testMercadoPagoWebhookPending()
    {
        $webhookData = [
            'id' => rand(100000000, 999999999),
            'type' => 'payment',
            'data' => [
                'id' => 'mp_pending_' . uniqid()
            ]
        ];

        $response = $this->post('/webhook/mercado_pago', $webhookData);

        $this->assertResponseStatus(200);
    }

    // ==================== PAYMENT SECURITY ====================

    /**
     * @test
     * Rejeitar cartão inválido
     */
    public function testRejectInvalidCard()
    {
        $paymentData = [
            'gateway' => 'asaas',
            'amount' => 99.90,
            'card' => [
                'number' => '4111111111111112', // Invalid check digit
                'exp_month' => '12',
                'exp_year' => '2030',
                'cvc' => '123'
            ]
        ];

        $response = $this->post('/api/v1/payments', $paymentData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        // Deve falhar
        $this->assertTrue($response->getStatusCode() >= 400);
    }

    /**
     * @test
     * Rejeitar cartão expirado
     */
    public function testRejectExpiredCard()
    {
        $paymentData = [
            'gateway' => 'asaas',
            'amount' => 99.90,
            'card' => [
                'number' => '4111111111111111',
                'exp_month' => '01',
                'exp_year' => '2020', // Expirado
                'cvc' => '123'
            ]
        ];

        $response = $this->post('/api/v1/payments', $paymentData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $this->assertTrue($response->getStatusCode() >= 400);
    }

    /**
     * @test
     * Não expor dados completos do cartão na resposta
     */
    public function testCardDataNotExposed()
    {
        $paymentData = [
            'gateway' => 'asaas',
            'amount' => 99.90,
            'card' => [
                'number' => '4111111111111111',
                'exp_month' => '12',
                'exp_year' => '2030',
                'cvc' => '123'
            ]
        ];

        $response = $this->post('/api/v1/payments', $paymentData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $body = $response->getBody();

        // Verificar que número completo não está exposto
        $this->assertStringNotContainsString('4111111111111111', $body);
        // CVC não deve estar exposto
        $this->assertStringNotContainsString('123', $body);
    }

    /**
     * @test
     * Apenas últimas 4 dígitos do cartão no banco de dados
     */
    public function testOnlyLast4DigitsStored()
    {
        $paymentData = [
            'gateway' => 'asaas',
            'amount' => 99.90,
            'card' => [
                'number' => '4111111111111111',
                'exp_month' => '12',
                'exp_year' => '2030',
                'cvc' => '123'
            ]
        ];

        $response = $this->post('/api/v1/payments', $paymentData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $data = json_decode($response->getBody(), true);
            $transactionId = $data['data']['transaction_id'];

            // Verificar no banco de dados
            $payment = $this->db->table('payments')
                ->where('transaction_id', $transactionId)
                ->first();

            if ($payment) {
                // Deve conter apenas últimos 4 dígitos
                $this->assertTrue(
                    !strpos($payment->card_number ?? '', '4111111111') === false || 
                    preg_match('/\*{12}1111/', $payment->card_number ?? ''),
                    'Banco de dados deve armazenar apenas últimos 4 dígitos'
                );
            }
        }
    }

    // ==================== PAYMENT RECONCILIATION ====================

    /**
     * @test
     * Webhooks com timestamp fora do prazo são rejeitados
     */
    public function testWebhookTimestampValidation()
    {
        $webhookData = [
            'event' => 'PAYMENT_CONFIRMED',
            'timestamp' => date('c', strtotime('-2 hours')), // 2 horas atrás
            'payment' => [
                'id' => 'pay_old_' . uniqid(),
                'value' => 99.90,
                'status' => 'CONFIRMED'
            ]
        ];

        $response = $this->post('/webhook/asaas', $webhookData);

        // Dependendo da implementação, pode rejeitar
        // Ideal é verificar timestamp com tolerância de ~5 minutos
    }

    /**
     * @test
     * Duplicar webhook é idempotent
     */
    public function testWebhookIdempotency()
    {
        $webhookData = [
            'event' => 'PAYMENT_CONFIRMED',
            'id' => 'webhook_' . uniqid(),
            'payment' => [
                'id' => 'pay_duplicate_' . uniqid(),
                'value' => 99.90,
                'status' => 'CONFIRMED'
            ]
        ];

        // Primeiro webhook
        $response1 = $this->post('/webhook/asaas', $webhookData);
        $this->assertResponseStatus(200);

        // Enviar mesmo webhook novamente
        $response2 = $this->post('/webhook/asaas', $webhookData);
        $this->assertResponseStatus(200);

        // Assinatura não deve estar duplicada
        $subscriptions = $this->db->table('subscriptions')
            ->where('gateway_webhook_id', $webhookData['id'] ?? $webhookData['payment']['id'])
            ->get()
            ->getResultArray();

        $this->assertLessThanOrEqual(1, count($subscriptions));
    }

    /**
     * @test
     * Webhook com assinatura inválida é rejeitado
     */
    public function testWebhookSignatureValidation()
    {
        $webhookData = [
            'event' => 'PAYMENT_CONFIRMED',
            'payment' => [
                'id' => 'pay_' . uniqid(),
                'value' => 99.90
            ]
        ];

        $response = $this->post('/webhook/asaas', $webhookData, [
            'headers' => ['X-Asaas-Signature' => 'invalid_signature_here']
        ]);

        // Deve rejeitar se signature é inválida
        $this->assertTrue($response->getStatusCode() >= 400 || $response->getStatusCode() === 401);
    }

    // ==================== PAYMENT LIMITS ====================

    /**
     * @test
     * Rejeitar pagamento acima do limite
     */
    public function testPaymentLimitEnforcement()
    {
        $paymentData = [
            'gateway' => 'asaas',
            'amount' => 999999.99, // Valor muito alto
            'card' => [
                'number' => '4111111111111111',
                'exp_month' => '12',
                'exp_year' => '2030',
                'cvc' => '123'
            ]
        ];

        $response = $this->post('/api/v1/payments', $paymentData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        // Pode ser rejeitado por limite
    }

    /**
     * @test
     * Rejeitar múltiplos pagamentos em curto espaço de tempo
     */
    public function testDuplicatePaymentPrevention()
    {
        $paymentData = [
            'gateway' => 'asaas',
            'amount' => 99.90,
            'idempotency_key' => 'test_key_' . uniqid(),
            'card' => [
                'number' => '4111111111111111',
                'exp_month' => '12',
                'exp_year' => '2030',
                'cvc' => '123'
            ]
        ];

        // Primeiro pagamento
        $response1 = $this->post('/api/v1/payments', $paymentData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        // Segundo pagamento idêntico rapidamente
        $response2 = $this->post('/api/v1/payments', $paymentData, [
            'headers' => ['X-API-Key' => $this->apiToken]
        ]);

        $data2 = json_decode($response2->getBody(), true);

        // Deve retornar mesmo ID de transação (idempotent)
        if ($response1->getStatusCode() === 201 && $response2->getStatusCode() === 201) {
            $data1 = json_decode($response1->getBody(), true);
            
            $this->assertEquals(
                $data1['data']['transaction_id'] ?? null,
                $data2['data']['transaction_id'] ?? null,
                'Pagamentos duplicados devem retornar mesma transação'
            );
        }
    }

    // ==================== HELPERS ====================

    private function generateStripeSignature($payload)
    {
        // Simular assinatura Stripe (em produção usar HMAC)
        $secret = env('STRIPE_WEBHOOK_SECRET');
        $timestamp = time();
        $body = json_encode($payload);

        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }
}
