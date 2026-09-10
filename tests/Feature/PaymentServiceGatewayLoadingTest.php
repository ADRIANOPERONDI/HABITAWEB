<?php

namespace Tests\Feature;

use App\Services\PaymentService;
use Tests\Support\HabitawebTestCase;

/**
 * `PaymentService::__construct(bool $loadGateway = true)` — quem já vai
 * chamar `setGateway()` na sequência (ex.: `AsaasSync`) não precisa que o
 * construtor carregue o gateway primário antes, só pra descartar em seguida.
 * Isso importa de verdade: cada carregamento decifra a config do gateway, e
 * `AsaasSync::run()` fazia isso duas vezes por execução (construtor + `setGateway`),
 * dobrando o log de erro sempre que a descriptografia falhava em produção.
 */
final class PaymentServiceGatewayLoadingTest extends HabitawebTestCase
{
    /** Torna esta conexão a única com is_primary=true, dentro da transação do teste. */
    private function makePrimaryGateway(): void
    {
        $db = \Config\Database::connect();
        $db->query('UPDATE payment_gateways SET is_primary = false');
        $db->table('payment_gateways')->insert([
            'code'       => 'fake_' . bin2hex(random_bytes(4)),
            'name'       => 'Gateway Fake',
            'class_name' => 'App\\PaymentGateways\\AsaasGateway',
            'is_active'  => true,
            'is_primary' => true,
        ]);
    }

    public function testLoadGatewayFalsoNaoCarregaNadaNoConstrutor(): void
    {
        $this->makePrimaryGateway();

        $service = new PaymentService(loadGateway: false);

        $this->assertNull($service->getActiveGateway());
    }

    public function testPadraoContinuaCarregandoOGatewayPrimarioImediatamente(): void
    {
        $this->makePrimaryGateway();

        $service = new PaymentService();

        $this->assertNotNull($service->getActiveGateway());
    }
}
