<?php

namespace Tests\Support\Integrations;

use App\Libraries\Integrations\Dto\CatalogItem;
use App\Libraries\Integrations\Dto\ExternalProperty;
use App\Libraries\Integrations\Dto\SyncCursor;
use App\Libraries\Integrations\Dto\TestResult;
use App\Libraries\Integrations\IntegrationProviderInterface;

/**
 * Conector roteirizado, para exercitar sync e outbox sem tocar em rede.
 *
 * Vive em tests/_support (autoloaded via composer) e não dentro de um arquivo
 * de teste: as suítes de sync e de outbox usam a mesma dublê, e declará-la num
 * dos dois arquivos quebra quando o outro roda sozinho.
 */
class FakeConnector implements IntegrationProviderInterface
{
    public int $resolveCalls = 0;
    public array $pushedLeads = [];

    /**
     * @param list<ExternalProperty|null> $catalogo   itens devolvidos pelo catálogo
     * @param \Throwable|null             $erro       lançado no catálogo e no push
     * @param TestResult|null             $leadResult resposta do pushLead
     */
    public function __construct(
        public array $catalogo = [],
        private ?\Throwable $erro = null,
        private ?TestResult $leadResult = null,
    ) {
    }

    public function configure(array $config): void
    {
    }

    public function validateConfig(): TestResult
    {
        if ($this->erro !== null) {
            return TestResult::fail($this->erro->getMessage());
        }

        return TestResult::ok('ok');
    }

    public function fetchCatalog(SyncCursor $cursor, array $settings = []): iterable
    {
        if ($this->erro !== null) {
            throw $this->erro;
        }

        foreach ($this->catalogo as $i => $property) {
            yield new CatalogItem(
                externalId: $property?->externalId ?? "vazio{$i}",
                externalCode: $property?->externalCode ?? "vazio{$i}",
                externalUpdatedAt: $property?->externalUpdatedAt,
                resolver: function () use ($property) {
                    $this->resolveCalls++;

                    return $property;
                },
            );
        }
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
        if ($this->erro !== null) {
            throw $this->erro;
        }

        $this->pushedLeads[] = $lead;

        return $this->leadResult ?? TestResult::ok('ok');
    }

    public function capabilities(): array
    {
        return [self::CAP_IMPORT_PROPERTIES, self::CAP_PUSH_LEADS];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }
}
