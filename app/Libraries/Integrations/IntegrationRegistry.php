<?php

namespace App\Libraries\Integrations;

use App\Entities\IntegrationProvider;
use App\Libraries\Integrations\Exceptions\IntegrationException;
use App\Models\IntegrationProviderModel;

/**
 * Resolve o código de um conector na classe que o implementa.
 *
 * Mesmo despacho por class_name usado em PaymentGatewayController para os
 * gateways. O ganho é o mesmo: adicionar um conector novo é escrever a classe e
 * inserir uma linha em integration_providers — nenhum controller, service ou
 * view precisa saber que ele existe.
 */
class IntegrationRegistry
{
    /** @var array<string, IntegrationProvider> */
    private array $providerCache = [];

    public function __construct(private ?IntegrationProviderModel $model = null)
    {
        $this->model ??= new IntegrationProviderModel();
    }

    public function findProvider(string $code): ?IntegrationProvider
    {
        if (! isset($this->providerCache[$code])) {
            $provider = $this->model->findByCode($code);

            if ($provider === null) {
                return null;
            }

            $this->providerCache[$code] = $provider;
        }

        return $this->providerCache[$code];
    }

    /** @return IntegrationProvider[] */
    public function listActive(): array
    {
        return $this->model->findActive();
    }

    /**
     * Instancia o conector já com as credenciais do tenant injetadas.
     *
     * @param array<string, string> $config
     *
     * @throws IntegrationException conector desconhecido, inativo ou mal registrado
     */
    public function make(string $code, array $config = []): IntegrationProviderInterface
    {
        $provider = $this->findProvider($code);

        if ($provider === null) {
            throw new IntegrationException("Conector \"{$code}\" não existe.");
        }

        if (! $provider->is_active) {
            throw new IntegrationException("O conector {$provider->name} está indisponível no momento.");
        }

        $class = (string) $provider->class_name;

        if ($class === '' || ! class_exists($class)) {
            // Erro de instalação, não do tenant: a linha do banco aponta para
            // uma classe que não existe (deploy incompleto, rename sem migration).
            log_message('critical', "Conector {$code} aponta para a classe inexistente \"{$class}\".");

            throw new IntegrationException("O conector {$provider->name} está mal configurado. Fale com o suporte.");
        }

        $instance = new $class();

        if (! $instance instanceof IntegrationProviderInterface) {
            log_message('critical', "Conector {$code}: {$class} não implementa IntegrationProviderInterface.");

            throw new IntegrationException("O conector {$provider->name} está mal configurado. Fale com o suporte.");
        }

        $instance->configure($config);

        return $instance;
    }
}
