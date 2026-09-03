<?php

namespace Tests\Support\Fakes;

/**
 * Gateway que sempre falha ao cobrar — para testar o caminho de erro de
 * `LeadChargeService::closeCycleForAccount()`: o débito de crédito precisa
 * ser desfeito quando a chamada ao gateway falha, senão a conta perde o
 * crédito do mês sem nunca ter sido cobrada de verdade por ele.
 */
class FailingPaymentGateway extends FakePaymentGateway
{
    public function createPayment(string $customerId, float $amount, array $data): array
    {
        throw new \RuntimeException('Falha simulada no gateway.');
    }
}
