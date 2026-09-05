<?php

namespace Tests\Support\Integrations;

use App\Entities\AccountIntegration;
use App\Libraries\Integrations\IntegrationProviderInterface;
use App\Services\IntegrationService;

/**
 * IntegrationService que devolve sempre o mesmo conector, no lugar do registry.
 *
 * Tudo o mais (credenciais, mapeamentos, escopo por tenant) continua real — só
 * a instanciação do conector é desviada.
 */
class FakeIntegrationService extends IntegrationService
{
    public function __construct(private IntegrationProviderInterface $connector)
    {
        parent::__construct();
    }

    public function makeConnector(AccountIntegration $integration): IntegrationProviderInterface
    {
        return $this->connector;
    }
}
