<?php

namespace App\Services;

use App\Entities\AccountIntegration;
use App\Entities\IntegrationProvider;
use App\Libraries\Integrations\Dto\TestResult;
use App\Libraries\Integrations\Exceptions\IntegrationException;
use App\Libraries\Integrations\IntegrationProviderInterface;
use App\Libraries\Integrations\IntegrationRegistry;
use App\Libraries\Integrations\Simob\SimobProvider;
use App\Libraries\Integrations\Simob\SimobVocabulary;
use App\Models\AccountIntegrationConfigModel;
use App\Models\AccountIntegrationModel;
use App\Models\IntegrationMappingModel;
use App\Models\PropertyExternalRefModel;

/**
 * Camada de aplicação das integrações: credenciais, teste de conexão e de/para.
 *
 * TODO método que recebe integração recebe também o accountId e confere a
 * posse. Não existe filtro de tenant no framework (ver CLAUDE.md), então o
 * escopo é responsabilidade de quem consulta — e aqui trafegam credenciais.
 */
class IntegrationService
{
    /**
     * Campos que o sync sobrescreve — a origem é a fonte da verdade.
     *
     * Editar qualquer um destes no admin é trabalho perdido: a próxima rodada
     * devolve o valor da plataforma externa. Por isso são bloqueados no
     * servidor (PropertyController::update) e travados na tela.
     *
     * Fica de fora, e continua editável, tudo que a origem não fornece:
     * destaque, meta tags, campos de curadoria, responsável e cliente.
     *
     * `status` NÃO entra aqui de propósito. A origem não tem conceito de
     * "rascunho" — quem decide isso é o tenant, revisando o que a
     * sincronização trouxe. IntegrationSyncService aplica initial_status
     * só na CRIAÇÃO do imóvel; a partir daí o status é do tenant, e uma
     * troca manual (inclusive a publicação em massa) sobrevive a qualquer
     * rodada seguinte. Sem essa exceção, todo imóvel importado nasceria
     * rascunho e morreria rascunho, sem nenhum caminho no painel para
     * publicá-lo.
     */
    public const MANAGED_FIELDS = [
        'titulo', 'descricao', 'tipo_negocio', 'tipo_imovel', 'preco',
        'rua', 'numero', 'complemento', 'bairro', 'cidade', 'estado', 'cep',
        'latitude', 'longitude',
        'quartos', 'suites', 'banheiros', 'vagas',
        'area_total', 'area_construida', 'area_privativa',
        'valor_condominio', 'iptu',
        'mobiliado', 'semimobiliado', 'aceita_pets', 'is_desocupado', 'is_exclusivo',
    ];

    public function __construct(
        private ?AccountIntegrationModel $integrationModel = null,
        private ?AccountIntegrationConfigModel $configModel = null,
        private ?IntegrationMappingModel $mappingModel = null,
        private ?IntegrationRegistry $registry = null,
        private ?PropertyExternalRefModel $refModel = null,
    ) {
        $this->integrationModel ??= model(AccountIntegrationModel::class);
        $this->configModel      ??= model(AccountIntegrationConfigModel::class);
        $this->mappingModel     ??= model(IntegrationMappingModel::class);
        $this->refModel         ??= model(PropertyExternalRefModel::class);
        $this->registry         ??= new IntegrationRegistry();
    }

    // ------------------------------------------------------------- consultas

    /** @return IntegrationProvider[] */
    public function availableProviders(): array
    {
        return $this->registry->listActive();
    }

    public function findProvider(string $code): ?IntegrationProvider
    {
        return $this->registry->findProvider($code);
    }

    public function find(int $accountId, string $providerCode): ?AccountIntegration
    {
        return $this->integrationModel->findForAccount($accountId, $providerCode);
    }

    /**
     * Integração do tenant, criando a linha se ainda não existir.
     *
     * A linha nasce desligada e PENDING: só um teste de conexão bem-sucedido
     * pode ligá-la.
     */
    public function findOrCreate(int $accountId, string $providerCode): AccountIntegration
    {
        $existing = $this->find($accountId, $providerCode);

        if ($existing !== null) {
            return $existing;
        }

        if ($this->registry->findProvider($providerCode) === null) {
            throw new IntegrationException("Conector \"{$providerCode}\" não existe.");
        }

        $id = $this->integrationModel->insert([
            'account_id'    => $accountId,
            'provider_code' => $providerCode,
            'is_active'     => false,
            'status'        => AccountIntegrationModel::STATUS_PENDING,
            'settings'      => AccountIntegration::defaultSettings(),
        ], true);

        return $this->integrationModel->find($id);
    }

    /**
     * Resumo de cada conector para a tela de listagem.
     *
     * @return list<array{provider:IntegrationProvider, integration:?AccountIntegration, unconfirmed:int, synced:int}>
     */
    public function overviewFor(int $accountId): array
    {
        $overview = [];

        foreach ($this->availableProviders() as $provider) {
            $integration = $this->find($accountId, $provider->code);

            $overview[] = [
                'provider'    => $provider,
                'integration' => $integration,
                'unconfirmed' => $integration ? $this->mappingModel->countUnconfirmed((int) $integration->id) : 0,
                'synced'      => $this->countSyncedProperties($accountId, $provider->code),
            ];
        }

        return $overview;
    }

    public function countSyncedProperties(int $accountId, string $providerCode): int
    {
        return $this->refModel
            ->where('account_id', $accountId)
            ->where('provider_code', $providerCode)
            ->countAllResults();
    }

    // ----------------------------------------------------------- credenciais

    /**
     * Grava as credenciais informadas no painel.
     *
     * Só aceita chaves declaradas no config_schema do conector: um POST forjado
     * não pode inventar campo de configuração.
     *
     * @param array<string, string> $input
     *
     * @return string[] Chaves efetivamente gravadas (para o audit log)
     */
    public function saveCredentials(AccountIntegration $integration, array $input): array
    {
        $provider = $this->registry->findProvider($integration->provider_code);

        if ($provider === null) {
            throw new IntegrationException('Conector não encontrado.');
        }

        $saved = [];

        foreach ($provider->getSchemaFields() as $field) {
            $key = $field['key'] ?? null;

            if ($key === null || ! array_key_exists($key, $input)) {
                continue;
            }

            $sensitive = ! empty($field['is_sensitive']);
            $value     = trim((string) $input[$key]);

            // Campo sensível em branco = "não mexi nisso": o painel sempre
            // renderiza a senha vazia, e sobrescrever apagaria o token.
            if ($sensitive && $value === '') {
                continue;
            }

            if ($this->configModel->saveConfig((int) $integration->id, $key, $value, $sensitive)) {
                $saved[] = $key;
            }
        }

        // Mudou credencial, o estado anterior de "conectado" não vale mais.
        $this->integrationModel->update($integration->id, [
            'status'            => AccountIntegrationModel::STATUS_PENDING,
            'last_test_message' => null,
        ]);

        return $saved;
    }

    public function saveSettings(AccountIntegration $integration, array $settings): bool
    {
        $current = $integration->settings();

        $clean = [
            'finalidades'    => array_values(array_intersect(
                array_map('intval', (array) ($settings['finalidades'] ?? $current['finalidades'])),
                [1, 2]
            )),
            'initial_status' => in_array($settings['initial_status'] ?? '', ['DRAFT', 'ACTIVE'], true)
                ? $settings['initial_status']
                : $current['initial_status'],
            'import_images'  => (bool) ($settings['import_images'] ?? false),
            'max_images'     => max(1, min(50, (int) ($settings['max_images'] ?? $current['max_images']))),
        ];

        // Nenhuma finalidade marcada traria catálogo vazio e pausaria tudo.
        if ($clean['finalidades'] === []) {
            $clean['finalidades'] = [1, 2];
        }

        return (bool) $this->integrationModel->update($integration->id, ['settings' => $clean]);
    }

    /** Credenciais decifradas, prontas para o conector. */
    public function credentials(AccountIntegration $integration): array
    {
        return $this->configModel->getConfig((int) $integration->id);
    }

    /** Credenciais para exibição: sensíveis viram ••••1234. */
    public function maskedCredentials(AccountIntegration $integration): array
    {
        return $this->configModel->getMaskedConfig((int) $integration->id);
    }

    /** Desconecta: apaga credenciais e desliga. Os imóveis já importados ficam. */
    public function disconnect(AccountIntegration $integration): void
    {
        $this->configModel->clearConfig((int) $integration->id);
        $this->integrationModel->update($integration->id, [
            'is_active'         => false,
            'status'            => AccountIntegrationModel::STATUS_PENDING,
            'last_test_message' => null,
        ]);
    }

    // ------------------------------------------------------- teste de conexão

    /**
     * Testa a conexão e registra o resultado na integração.
     *
     * Sucesso semeia o de/para automaticamente: sem isso o tenant teria uma
     * tela de mapeamento vazia e não saberia o que fazer com ela.
     */
    public function testConnection(AccountIntegration $integration): TestResult
    {
        $connector = null;

        try {
            $connector = $this->makeConnector($integration);
            $result    = $connector->validateConfig();
        } catch (IntegrationException $e) {
            $result = TestResult::fail($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[IntegrationService] Erro inesperado no teste de conexão: ' . $e->getMessage());
            $result = TestResult::fail('Falha inesperada ao testar a conexão. Tente novamente.');
        }

        $this->integrationModel->markTested((int) $integration->id, $result->success, $result->message);

        if ($result->success && $connector !== null) {
            try {
                $this->seedMappings($integration, $connector);
            } catch (\Throwable $e) {
                // Descoberta é conveniência: falhar aqui não invalida uma
                // conexão que comprovadamente funciona.
                log_message('warning', '[IntegrationService] Descoberta de mapeamentos falhou: ' . $e->getMessage());
            }
        }

        return $result;
    }

    /** Instancia o conector com credenciais, preferências e de/para carregados. */
    public function makeConnector(AccountIntegration $integration): IntegrationProviderInterface
    {
        $connector = $this->registry->make(
            $integration->provider_code,
            $this->credentials($integration)
        );

        if ($connector instanceof SimobProvider) {
            $connector->setSyncSettings($integration->settings());
            $connector->loadMappings((int) $integration->id, $this->mappingModel);
        }

        return $connector;
    }

    // ------------------------------------------------------------ mapeamentos

    /**
     * Descobre os de/para da origem e semeia os que ainda não existem.
     *
     * Nunca sobrescreve linha existente — a escolha do tenant vale mais que o
     * palpite (ver IntegrationMappingModel::seedSuggestion).
     *
     * @return array{category:int, characteristic:int} quantos foram criados
     */
    public function seedMappings(AccountIntegration $integration, ?IntegrationProviderInterface $connector = null): array
    {
        $connector ??= $this->makeConnector($integration);
        $discovered  = $connector->discoverMappings();
        $created     = ['category' => 0, 'characteristic' => 0];

        foreach ([IntegrationMappingModel::KIND_CATEGORY, IntegrationMappingModel::KIND_CHARACTERISTIC] as $kind) {
            foreach ($discovered[$kind] ?? [] as $item) {
                $label = (string) ($item['external_label'] ?? '');

                $guess = $kind === IntegrationMappingModel::KIND_CATEGORY
                    ? ['target_value' => SimobVocabulary::guessPropertyType($label)]
                    : ['target_field' => SimobVocabulary::guessTargetField($label)];

                $inserted = $this->mappingModel->seedSuggestion((int) $integration->id, $kind, array_merge([
                    'external_id'    => (string) $item['external_id'],
                    'external_label' => $label,
                    'external_type'  => $item['external_type'] ?? null,
                ], $guess));

                if ($inserted) {
                    $created[$kind]++;
                }
            }
        }

        return $created;
    }

    /**
     * Grava o de/para revisado pelo tenant.
     *
     * @param array<string, array{target?:string}> $input external_id => escolha
     */
    public function saveMappings(AccountIntegration $integration, string $kind, array $input): int
    {
        $valid = $kind === IntegrationMappingModel::KIND_CATEGORY
            ? SimobVocabulary::propertyTypes()
            : SimobVocabulary::targetFields();

        $existing = $this->mappingModel->indexedBy((int) $integration->id, $kind);
        $saved    = 0;

        foreach ($input as $externalId => $choice) {
            $mapping = $existing[(string) $externalId] ?? null;

            if ($mapping === null) {
                continue;
            }

            $target = trim((string) ($choice['target'] ?? ''));

            // Destino fora da lista branca é descartado, não gravado: senão um
            // POST forjado escreveria em coluna arbitrária de properties
            // (incluindo as de PropertyService::GUARDED_FIELDS).
            if ($target !== '' && ! isset($valid[$target])) {
                continue;
            }

            $data = $kind === IntegrationMappingModel::KIND_CATEGORY
                ? ['target_value' => $target ?: null]
                : ['target_field' => $target ?: null];

            $this->mappingModel->update($mapping->id, $data + ['is_confirmed' => true]);
            $saved++;
        }

        return $saved;
    }

    /** @return \App\Entities\IntegrationMapping[] */
    public function mappingsOf(AccountIntegration $integration, string $kind): array
    {
        return $this->mappingModel->listByKind((int) $integration->id, $kind);
    }

    public function countUnconfirmed(AccountIntegration $integration): int
    {
        return $this->mappingModel->countUnconfirmed((int) $integration->id);
    }

    // ------------------------------------------------------ espelho read-only

    /**
     * O imóvel é espelho de uma plataforma externa?
     *
     * Usado para travar a edição no admin: a origem é a fonte da verdade e o
     * próximo sync sobrescreveria qualquer alteração feita aqui.
     */
    public function isManagedProperty(int $propertyId): bool
    {
        return $this->refModel->isManaged($propertyId);
    }

    public function refForProperty(int $propertyId): ?\App\Entities\PropertyExternalRef
    {
        return $this->refModel->findByProperty($propertyId);
    }

    // ------------------------------------------------------------- ativação

    /**
     * Liga ou desliga o sync automático.
     *
     * Ligar exige uma conexão comprovada — senão o cron ficaria tentando de
     * 30 em 30 minutos com credencial que nunca funcionou.
     */
    public function toggleActive(AccountIntegration $integration, bool $active): TestResult
    {
        if ($active && ! $integration->isConnected()) {
            return TestResult::fail('Teste a conexão antes de ativar a sincronização automática.');
        }

        $this->integrationModel->update($integration->id, [
            'is_active' => $active,
            'status'    => $active
                ? AccountIntegrationModel::STATUS_CONNECTED
                : AccountIntegrationModel::STATUS_PAUSED,
        ]);

        return TestResult::ok($active ? 'Sincronização automática ativada.' : 'Sincronização automática pausada.');
    }

    /**
     * Marca "sincronizar agora": o cron `integration:sync` (a cada 1 min)
     * trata esta integração antes das outras (ver
     * AccountIntegrationModel::dueForSync()) e limpa este campo ao consumir
     * (IntegrationSyncService::run()). Quem chama isso NUNCA deve rodar o
     * sync diretamente — é exatamente o que causava o timeout no request web.
     */
    public function markPriority(AccountIntegration $integration): void
    {
        $this->integrationModel->update($integration->id, [
            'sync_priority_requested_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
