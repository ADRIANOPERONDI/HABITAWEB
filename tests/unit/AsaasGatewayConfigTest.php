<?php

namespace Tests\Unit;

use App\PaymentGateways\AsaasGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AsaasGatewayConfigTest extends TestCase
{
    #[DataProvider('invalidApiKeys')]
    public function testRejectsInvalidApiKeyBeforeSendingRequest(string $apiKey): void
    {
        $gateway = new AsaasGateway();
        $gateway->configure([
            'api_key'     => $apiKey,
            'environment' => 'sandbox',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ASAAS_API_KEY não configurada');

        $gateway->request('GET', '/customers?limit=1');
    }

    public static function invalidApiKeys(): array
    {
        return [
            'empty'       => [''],
            'placeholder' => ['your_api_key'],
        ];
    }
}
