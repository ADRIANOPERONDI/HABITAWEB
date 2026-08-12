<?php

namespace App\Libraries\Integrations;

use App\Libraries\Integrations\Dto\ExternalProperty;
use App\Libraries\Integrations\Dto\SyncCursor;
use App\Libraries\Integrations\Dto\TestResult;

/**
 * Contrato de um conector de plataforma externa.
 *
 * Espelha a ideia de App\PaymentGateways\GatewayInterface: a classe é resolvida
 * em runtime por integration_providers.class_name, então controller, service e
 * comando não conhecem nenhum conector concreto.
 *
 * Nem todo conector faz tudo — quem só importa imóveis declara apenas
 * CAP_IMPORT_PROPERTIES em capabilities() e pode lançar
 * \BadMethodCallException em pushLead(). O chamador consulta supports() antes.
 */
interface IntegrationProviderInterface
{
    public const CAP_IMPORT_PROPERTIES = 'import_properties';
    public const CAP_PUSH_LEADS        = 'push_leads';

    /**
     * Injeta as credenciais do tenant. Chamado antes de qualquer outro método.
     *
     * @param array<string, string> $config Chaves definidas no config_schema do conector
     */
    public function configure(array $config): void;

    /**
     * Bate na plataforma externa e confirma que a credencial funciona.
     *
     * Deve usar o endpoint mais barato disponível — é chamado pelo botão
     * "Testar conexão", de forma síncrona, com o usuário esperando na tela.
     */
    public function validateConfig(): TestResult;

    /**
     * Percorre o catálogo do tenant, do mais recente para o mais antigo.
     *
     * Devolve um iterável (generator) e não um array: catálogo de imobiliária
     * passa fácil de mil imóveis com dezenas de imagens cada, e materializar
     * tudo em memória antes de gravar o primeiro estoura o limite do PHP.
     *
     * Cabe ao conector parar de paginar quando o cursor indicar que já chegou
     * em conteúdo antigo.
     *
     * @return iterable<ExternalProperty>
     */
    public function fetchCatalog(SyncCursor $cursor, array $settings = []): iterable;

    /**
     * Detalhe completo de um imóvel — descrição, todas as imagens, todas as
     * características. A listagem costuma vir resumida.
     */
    public function fetchPropertyDetail(string $externalId): ?ExternalProperty;

    /**
     * Descobre os de/para disponíveis na origem (categorias, características).
     *
     * Alimenta a tela de mapeamentos. Retorna, por tipo:
     * ['category' => [['external_id'=>'17','external_label'=>'APARTAMENTO'], ...], ...]
     *
     * @return array<string, list<array{external_id:string, external_label:string, external_type?:string}>>
     */
    public function discoverMappings(): array;

    /**
     * Empurra um lead capturado no Habitaweb para o CRM da plataforma externa.
     *
     * @param array<string, mixed> $lead Payload montado pelo IntegrationOutbox
     */
    public function pushLead(array $lead): TestResult;

    /** @return string[] Constantes CAP_* suportadas */
    public function capabilities(): array;

    public function supports(string $capability): bool;
}
