<?php

namespace App\Libraries\Integrations\Simob;

use App\Entities\IntegrationMapping;
use App\Libraries\Integrations\AbstractProvider;
use App\Libraries\Integrations\Dto\CatalogItem;
use App\Libraries\Integrations\Dto\ExternalProperty;
use App\Libraries\Integrations\Dto\SyncCursor;
use App\Libraries\Integrations\Dto\TestResult;
use App\Libraries\Integrations\Exceptions\AuthException;
use App\Libraries\Integrations\Exceptions\IntegrationException;
use App\Libraries\Integrations\Http\IntegrationHttpClient;
use App\Libraries\Integrations\IntegrationProviderInterface;
use App\Models\IntegrationMappingModel;

/**
 * Conector do Simob (Flexpro Sistemas).
 *
 * Direção da integração, ditada pela API deles:
 *   imóveis  Simob -> Habitaweb  (a API é somente leitura para imóveis)
 *   leads    Habitaweb -> Simob  (via /crm_interesse/create)
 */
class SimobProvider extends AbstractProvider
{
    /**
     * Teto de páginas por finalidade numa rodada.
     *
     * Existe para o cron não ficar preso a vida toda num catálogo gigante nem
     * num laço infinito caso a API pare de respeitar o offset. Com PAGE_SIZE
     * 50, são 10 mil imóveis por finalidade por rodada — a próxima passada
     * continua de onde parou.
     */
    private const MAX_PAGES = 200;

    private ?SimobClient $client = null;

    /** @var array<string, IntegrationMapping> */
    private array $categoryMap = [];

    /** @var array<string, IntegrationMapping> */
    private array $characteristicMap = [];

    private ?SimobPropertyMapper $mapper = null;

    public function capabilities(): array
    {
        return [
            IntegrationProviderInterface::CAP_IMPORT_PROPERTIES,
            IntegrationProviderInterface::CAP_PUSH_LEADS,
        ];
    }

    /**
     * Carrega o de/para do tenant.
     *
     * Fica fora de configure() porque as credenciais e os mapeamentos vêm de
     * lugares diferentes, e o teste de conexão não precisa dos mapeamentos.
     */
    public function loadMappings(int $accountIntegrationId, ?IntegrationMappingModel $model = null): void
    {
        $model ??= new IntegrationMappingModel();

        $this->categoryMap       = $model->indexedBy($accountIntegrationId, IntegrationMappingModel::KIND_CATEGORY);
        $this->characteristicMap = $model->indexedBy($accountIntegrationId, IntegrationMappingModel::KIND_CHARACTERISTIC);
        $this->mapper            = null;
    }

    /** @param array<string, IntegrationMapping> $categories */
    public function setMappings(array $categories, array $characteristics): void
    {
        $this->categoryMap       = $categories;
        $this->characteristicMap = $characteristics;
        $this->mapper            = null;
    }

    // ------------------------------------------------------ teste de conexão

    public function validateConfig(): TestResult
    {
        try {
            // categorias-imoveis é a chamada mais barata que exige o token, e o
            // retorno já serve de semente para o mapeamento de tipos.
            $categorias = $this->client()->listCategories(3);
        } catch (AuthException $e) {
            return TestResult::fail($e->getMessage());
        } catch (IntegrationException $e) {
            return TestResult::fail($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[Simob] Falha inesperada no teste de conexão: ' . $e->getMessage());

            return TestResult::fail('Falha inesperada ao contatar o Simob. Tente novamente em alguns minutos.');
        }

        if ($categorias === []) {
            // 200 com lista vazia: token válido mas sem catálogo liberado, ou
            // URL de outra imobiliária. Não é sucesso silencioso.
            return TestResult::fail(
                'Conexão estabelecida, mas o Simob não devolveu nenhuma categoria de imóvel. '
                . 'Confira se o token pertence a esta imobiliária e se há imóveis liberados para o site.'
            );
        }

        return TestResult::ok(
            sprintf('Conectado ao Simob: %d categoria(s) de imóvel encontrada(s).', count($categorias)),
            ['categorias' => count($categorias)]
        );
    }

    // ----------------------------------------------------------- mapeamentos

    public function discoverMappings(): array
    {
        $categorias = [];

        foreach ($this->client()->listCategories(3) as $cat) {
            $id = (string) ($cat['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $categorias[] = [
                'external_id'    => $id,
                'external_label' => (string) ($cat['descricao'] ?? $id),
            ];
        }

        // As características são por finalidade; juntar as duas evita que um
        // atributo só de locação (ou só de venda) fique de fora do de/para.
        $caracteristicas = [];

        foreach ([SimobClient::FINALIDADE_LOCACAO, SimobClient::FINALIDADE_VENDA] as $finalidade) {
            try {
                $lista = $this->client()->listCharacteristics($finalidade);
            } catch (IntegrationException $e) {
                log_message('warning', "[Simob] Características da finalidade {$finalidade} indisponíveis: " . $e->getMessage());

                continue;
            }

            foreach ($lista as $carac) {
                $id = (string) ($carac['id'] ?? '');

                if ($id === '' || isset($caracteristicas[$id])) {
                    continue;
                }

                $caracteristicas[$id] = [
                    'external_id'    => $id,
                    'external_label' => (string) ($carac['descricao'] ?? $id),
                    'external_type'  => (string) ($carac['idTipoCaracteristica'] ?? $carac['tipo'] ?? ''),
                ];
            }
        }

        return [
            'category'       => $categorias,
            'characteristic' => array_values($caracteristicas),
        ];
    }

    // -------------------------------------------------------------- catálogo

    /**
     * Percorre o catálogo, da atualização mais recente para a mais antiga.
     *
     * Generator, e não array: catálogo de imobiliária passa fácil de mil
     * imóveis com dezenas de imagens, e materializar tudo antes de gravar o
     * primeiro estoura a memória do PHP.
     *
     * O detalhe de cada imóvel só é buscado quando o chamador pede — daí o
     * yield entregar um CatalogItem em vez do ExternalProperty pronto. É o que
     * permite pular, sem gastar uma requisição, o imóvel cujo updatedAt não
     * mudou desde o último sync.
     *
     * @return iterable<CatalogItem>
     */
    public function fetchCatalog(SyncCursor $cursor, array $settings = []): iterable
    {
        $finalidades = $settings['finalidades'] ?? [SimobClient::FINALIDADE_LOCACAO, SimobClient::FINALIDADE_VENDA];
        $seen        = [];

        foreach ($finalidades as $finalidade) {
            $finalidade = (int) $finalidade;
            $page       = 0;

            while ($page < self::MAX_PAGES) {
                $itens = $this->client()->listProperties($finalidade, $page * SimobClient::PAGE_SIZE);

                if ($itens === []) {
                    break;
                }

                foreach ($itens as $item) {
                    $resumo = $this->mapper()->summarize($item);

                    if ($resumo === null) {
                        continue;
                    }

                    // O mesmo imóvel aparece nas duas finalidades quando está à
                    // venda e para alugar. O detalhe resolve os dois lados de
                    // uma vez, então basta processá-lo na primeira aparição.
                    if (isset($seen[$resumo['external_id']])) {
                        continue;
                    }

                    $seen[$resumo['external_id']] = true;

                    yield new CatalogItem(
                        externalId: $resumo['external_id'],
                        externalCode: $resumo['external_code'],
                        externalUpdatedAt: $resumo['updated_at'],
                        resolver: fn (): ?ExternalProperty => $this->resolveDetail($resumo['external_code'], $item),
                    );
                }

                // Página incompleta = fim do catálogo.
                if (count($itens) < SimobClient::PAGE_SIZE) {
                    break;
                }

                // A listagem vem ordenada por atualização desc: assim que o
                // último item da página já é anterior ao corte, todo o resto
                // também é. Sem isso o sync varreria o catálogo inteiro toda
                // rodada — a API não tem filtro updated_since.
                $ultimo = $this->mapper()->summarize($itens[count($itens) - 1]);

                if ($ultimo !== null && $cursor->isBefore($ultimo['updated_at'])) {
                    break;
                }

                $page++;
            }
        }
    }

    public function fetchPropertyDetail(string $externalId): ?ExternalProperty
    {
        return $this->resolveDetail($externalId, []);
    }

    private function resolveDetail(string $codigo, array $listItem): ?ExternalProperty
    {
        $detail = $this->client()->getPropertyDetail($codigo);

        if ($detail === null) {
            // O detalhe pode falhar para um imóvel específico (a doc avisa que
            // certas rotas nem sempre estão online). Mapeia com o que a
            // listagem trouxe em vez de perder o imóvel.
            return $listItem === [] ? null : $this->mapper()->mapDetail($listItem, $listItem);
        }

        return $this->mapper()->mapDetail($detail, $listItem);
    }

    // ------------------------------------------------------------------ lead

    public function pushLead(array $lead): TestResult
    {
        $interesse = (new SimobLeadMapper())->map(
            $lead['lead'] ?? $lead,
            $lead['property'] ?? [],
            $lead['options'] ?? []
        );

        try {
            $response = $this->client()->createInterest([$interesse]);
        } catch (AuthException | IntegrationException $e) {
            return TestResult::fail($e->getMessage());
        }

        if (($response['success'] ?? true) === false) {
            $mensagem = is_string($response['message'] ?? null) ? $response['message'] : 'sem detalhe';

            return TestResult::fail('O Simob recusou o interesse: ' . $mensagem);
        }

        return TestResult::ok('Lead enviado ao SimobCRM.', ['response' => $response['result'] ?? null]);
    }

    // --------------------------------------------------------------- interno

    public function client(): SimobClient
    {
        if ($this->client === null) {
            $this->client = new SimobClient($this->http ?? $this->buildHttpClient());
        }

        return $this->client;
    }

    /** Injeta um client falso nos testes, sem tocar em rede. */
    public function setClient(SimobClient $client): void
    {
        $this->client = $client;
    }

    private function buildHttpClient(): IntegrationHttpClient
    {
        $baseUrl = $this->requireConfig('base_url', 'URL da imobiliária');
        $token   = $this->requireConfig('token', 'Token de integração');

        return new IntegrationHttpClient(
            $baseUrl,
            ['Authorization' => 'Bearer ' . $token],
            'Simob'
        );
    }

    private function mapper(): SimobPropertyMapper
    {
        if ($this->mapper === null) {
            $this->mapper = new SimobPropertyMapper(
                $this->optionalConfig('base_url'),
                $this->categoryMap,
                $this->characteristicMap,
                $this->config['__settings'] ?? [],
            );
        }

        return $this->mapper;
    }

    /**
     * As preferências do tenant (status inicial, máximo de imagens) chegam pelo
     * mesmo array de configure() para não inchar a interface com um método a
     * mais que só o Simob usaria.
     */
    public function setSyncSettings(array $settings): void
    {
        $this->config['__settings'] = $settings;
        $this->mapper               = null;
    }
}
