<?php

namespace App\Libraries\Integrations;

use App\Libraries\Integrations\Dto\ExternalProperty;
use App\Libraries\Integrations\Dto\TestResult;
use App\Libraries\Integrations\Exceptions\IntegrationException;
use App\Libraries\Integrations\Http\IntegrationHttpClient;

/**
 * Base dos conectores: guarda credenciais e dá os "não implementado" padrão.
 *
 * Só o que é comum a qualquer plataforma mora aqui. Tudo que for específico
 * (formato de payload, nome de campo, paginação) fica na subclasse.
 */
abstract class AbstractProvider implements IntegrationProviderInterface
{
    /** @var array<string, string> */
    protected array $config = [];

    protected ?IntegrationHttpClient $http = null;

    public function configure(array $config): void
    {
        $this->config = $config;
        // Trocar credencial invalida o cliente já montado (ele carrega o token
        // nos headers padrão).
        $this->http = null;
    }

    /**
     * Credencial obrigatória. Lança em vez de devolver '' porque uma chamada
     * autenticada com token vazio dá 401 e confunde o diagnóstico no painel.
     */
    protected function requireConfig(string $key, string $label): string
    {
        $value = trim((string) ($this->config[$key] ?? ''));

        if ($value === '') {
            throw new IntegrationException("{$label} não configurado(a). Preencha o campo e salve antes de continuar.");
        }

        return $value;
    }

    protected function optionalConfig(string $key, string $default = ''): string
    {
        return trim((string) ($this->config[$key] ?? $default));
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * Permite injetar um cliente falso nos testes, no mesmo espírito do
     * PropertyService::setImageFetcher().
     */
    public function setHttpClient(IntegrationHttpClient $client): void
    {
        $this->http = $client;
    }

    public function fetchPropertyDetail(string $externalId): ?ExternalProperty
    {
        return null;
    }

    public function discoverMappings(): array
    {
        return [];
    }

    public function pushLead(array $lead): TestResult
    {
        return TestResult::fail('Este conector não envia leads para a plataforma externa.');
    }
}
