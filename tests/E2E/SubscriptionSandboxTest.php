<?php

namespace Tests\E2E;

use App\Database\Seeds\PaymentGatewaysSeeder;
use App\Database\Seeds\PlanSeeder;
use App\Services\PaymentService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Factories\TenantFactory;
use Tests\Support\HabitawebTestCase;

/**
 * Testes de integração REAIS contra o sandbox do Asaas — batem na API de verdade
 * (rede), diferente do resto da suíte (stub/local). Por isso ficam isolados no
 * grupo 'asaas-sandbox': excluídos do caminho padrão (`./run_tests.sh all`) e do
 * CI, e só rodam via `./run_tests.sh sandbox` com credenciais de sandbox
 * carregadas de .env.testing (ASAAS_ENV=sandbox, ASAAS_API_KEY...).
 *
 * IMPORTANTE: nenhum valor de credencial é impresso em asserção/mensagem/log — só
 * a PRESENÇA das chaves é usada para decidir rodar ou pular (markTestSkipped).
 *
 * Este arquivo foi escrito e verificado sintaticamente, mas — como o ambiente de
 * desenvolvimento não tem credenciais reais de sandbox configuradas — a primeira
 * execução ponta-a-ponta contra a API real do Asaas ainda precisa ser feita por
 * quem tiver essas credenciais (confirmar campos de resposta, tempo de execução,
 * e se o CPF fake gerado abaixo é aceito pelo validador do Asaas).
 */
#[Group('asaas-sandbox')]
final class SubscriptionSandboxTest extends HabitawebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // .env.testing não é carregado pelo bootstrap padrão do PHPUnit (ver
        // run_tests.sh) — carregamos explicitamente aqui, só para este grupo,
        // usando o mecanismo do próprio CI4. Se o arquivo tiver algum valor mal
        // formatado (o parser do CI4 é estrito), degrada para "sem credenciais"
        // em vez de quebrar o teste com um erro de parsing confuso.
        if (is_file(ROOTPATH . '.env.testing')) {
            try {
                (new \CodeIgniter\Config\DotEnv(ROOTPATH, '.env.testing'))->load();
            } catch (\Throwable $e) {
                $this->markTestSkipped('Falha ao ler .env.testing: ' . $e->getMessage());
            }
        }

        if (env('ASAAS_ENV') !== 'sandbox' || trim((string) env('ASAAS_API_KEY')) === '') {
            $this->markTestSkipped(
                'Credenciais de sandbox do Asaas não configuradas (ASAAS_ENV=sandbox + '
                . 'ASAAS_API_KEY em .env.testing). Pulando teste de integração real.'
            );
        }

        $this->seed(PlanSeeder::class);
        ob_start(); // PaymentGatewaysSeeder faz echo de progresso — suprime para não marcar o teste como "risky".
        $this->seed(PaymentGatewaysSeeder::class);
        ob_end_clean();
    }

    public function testCreateCustomerAndSubscriptionInSandbox(): void
    {
        $tenant = (new TenantFactory())->create([
            'documento' => $this->generateFakeValidCpf(),
        ]);

        $plan = model('App\Models\PlanModel')->where('chave', 'PRATA')->first();
        $this->assertNotNull($plan, 'PlanSeeder deveria ter criado o plano PRATA.');

        $paymentService = (new PaymentService())->setGateway('asaas');

        $customerId = $paymentService->getOrCreateCustomer($tenant['account']->id);
        $this->assertNotEmpty($customerId, 'Deveria retornar um customer_id real do Asaas sandbox.');

        $result = $paymentService->initializeSubscription(
            accountId: $tenant['account']->id,
            planId: $plan->id,
            billingType: 'PIX',
            billingCycle: 'MONTHLY'
        );

        $this->assertIsArray($result);
        $this->assertArrayHasKey('subscription_id', $result);
        $this->assertNotEmpty($result['subscription_id']);

        $this->assertDatabaseHas('subscriptions', [
            'account_id' => $tenant['account']->id,
            'plan_id' => $plan->id,
        ]);
    }

    /**
     * Gera um CPF com dígitos verificadores válidos (algoritmo padrão), mas com
     * base numérica aleatória — o Asaas costuma validar o formato/checksum mesmo
     * em sandbox, então um CPF sequencial simples (ex.: 00000000000) é rejeitado.
     */
    private function generateFakeValidCpf(): string
    {
        $base = [];
        for ($i = 0; $i < 9; $i++) {
            $base[] = random_int(0, 9);
        }

        $calcDigit = function (array $digits): int {
            $sum = 0;
            $weight = count($digits) + 1;
            foreach ($digits as $d) {
                $sum += $d * $weight--;
            }
            $rest = $sum % 11;
            return $rest < 2 ? 0 : 11 - $rest;
        };

        $d1 = $calcDigit($base);
        $d2 = $calcDigit([...$base, $d1]);

        return implode('', $base) . $d1 . $d2;
    }
}
