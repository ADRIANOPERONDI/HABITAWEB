<?php

namespace App\Services;

use App\Entities\AccountIntegration;
use App\Libraries\Geo\GeocoderInterface;
use App\Libraries\Geo\NullGeocoder;
use App\Libraries\Integrations\Dto\CatalogItem;
use App\Libraries\Integrations\Dto\ExternalProperty;
use App\Libraries\Integrations\Dto\SyncCursor;
use App\Libraries\Integrations\Dto\SyncResult;
use App\Libraries\Integrations\Exceptions\AuthException;
use App\Libraries\Integrations\Exceptions\IntegrationException;
use App\Libraries\Integrations\Exceptions\RateLimitException;
use App\Libraries\Integrations\IntegrationProviderInterface;
use App\Libraries\Integrations\Simob\SimobProvider;
use App\Models\AccountIntegrationModel;
use App\Models\IntegrationSyncRunModel;
use App\Models\PropertyExternalRefModel;
use App\Models\PropertyModel;

/**
 * Traz o catálogo da plataforma externa para o Habitaweb.
 *
 * O que uma rodada faz, em ordem:
 *   1. Abre um registro em integration_sync_runs e trava a integração.
 *   2. Percorre o catálogo (o conector já entrega ordenado por atualização
 *      desc e para de paginar sozinho ao passar do último sync).
 *   3. Para cada item: se o updatedAt da origem é o mesmo que já está gravado,
 *      pula sem nem buscar o detalhe — é a economia que torna o sync barato.
 *   4. Quem mudou vira upsert via PropertyService, com as imagens.
 *   5. Quem sumiu do catálogo é PAUSADO, nunca deletado.
 *
 * O imóvel importado é ESPELHO: a origem é a fonte da verdade e o próximo sync
 * sobrescreve o que for editado aqui. Quem trava a edição no admin é o
 * IntegrationService::isManagedProperty().
 */
class IntegrationSyncService
{
    /** Uma rodada nunca passa disto, para o cron não ficar preso num tenant. */
    /** Testável via construtor — o valor de produção é o default. */
    private int $maxItemsPerRun = 2000;

    /** Trava para não haver duas rodadas simultâneas da mesma integração. */
    private const LOCK_TTL = 1800;

    /**
     * Geocodificação é I/O externo lento (a Nominatim exige ~1 req/s) — um
     * catálogo de milhares de imóveis sem coordenada não pode multiplicar o
     * tempo da rodada por mil. O que passar do teto fica sem lat/lng nesta
     * rodada e tenta de novo na próxima (o item só entra aqui quando ainda
     * não tem coordenada, então nunca fica de fora para sempre).
     */
    private const MAX_GEOCODE_PER_RUN = 100;

    private int $geocodedThisRun = 0;

    public function __construct(
        private ?IntegrationService $integrationService = null,
        private ?PropertyService $propertyService = null,
        private ?PropertyExternalRefModel $refModel = null,
        private ?AccountIntegrationModel $integrationModel = null,
        private ?IntegrationSyncRunModel $runModel = null,
        private ?PropertyModel $propertyModel = null,
        private ?GeocoderInterface $geocoder = null,
    ) {
        $this->integrationService ??= new IntegrationService();
        $this->propertyService    ??= new PropertyService();
        $this->refModel           ??= model(PropertyExternalRefModel::class);
        $this->integrationModel   ??= model(AccountIntegrationModel::class);
        $this->runModel           ??= model(IntegrationSyncRunModel::class);
        $this->propertyModel      ??= model(PropertyModel::class);
        // NullGeocoder por padrão de propósito — não NominatimGeocoder: um
        // geocoder real faz I/O de rede de verdade (com throttle de ~1s por
        // consulta), e este é o construtor que TODA a suíte de testes usa
        // quando não injeta nada explicitamente. O único chamador de
        // produção (spark integration:sync) passa NominatimGeocoder na mão.
        $this->geocoder           ??= new NullGeocoder();
    }

    /** Só para teste: exercitar o corte do teto de itens sem simular 2000 imóveis de verdade. */
    public function setMaxItemsPerRun(int $max): void
    {
        $this->maxItemsPerRun = $max;
    }

    /**
     * Roda uma sincronização completa da integração.
     *
     * @param bool $forceFull ignora o cursor e varre o catálogo inteiro
     */
    public function run(AccountIntegration $integration, string $trigger = IntegrationSyncRunModel::TRIGGER_CRON, bool $forceFull = false): SyncResult
    {
        $result   = new SyncResult();
        $lockKey  = 'integration_sync_lock_' . $integration->id;
        $startedAt = date('Y-m-d H:i:s');
        $this->geocodedThisRun = 0;

        if (cache($lockKey) !== null) {
            $result->addError('Já existe uma sincronização em andamento para esta integração.');

            return $result;
        }

        cache()->save($lockKey, time(), self::LOCK_TTL);

        $runId = $this->runModel->start((int) $integration->id, $trigger);

        try {
            $connector = $this->integrationService->makeConnector($integration);

            if (! $connector->supports(IntegrationProviderInterface::CAP_IMPORT_PROPERTIES)) {
                throw new IntegrationException('Este conector não importa imóveis.');
            }

            // Rodada completa: semeia sugestão pra categoria/característica
            // NUNCA vista antes de processar o catálogo. Sem isto, um item
            // de categoria nova ficaria "ignorado" até alguém abrir a tela de
            // mapeamentos e clicar em "Redescobrir" manualmente — o --full já
            // busca o catálogo inteiro da origem, então descobrir o de/para
            // junto é a mesma viagem, não uma chamada extra.
            if ($forceFull && $connector instanceof SimobProvider) {
                $this->integrationService->seedMappings($integration, $connector);
                $connector->loadMappings((int) $integration->id);
            }

            $catalogoCompleto = $this->consume($integration, $connector, $result, $runId, $forceFull);
            $this->pauseVanished($integration, $result, $runId, $forceFull && $catalogoCompleto);

            $this->runModel->finish($runId, $result->status(), $result->toCounters(), $result->errorSummary());

            $atualizacoes = [
                'status'                     => AccountIntegrationModel::STATUS_CONNECTED,
                // Pedido de "sincronizar agora" foi atendido nesta rodada.
                'sync_priority_requested_at' => null,
            ];

            // Só avança o corte incremental quando o catálogo foi percorrido
            // até o fim. Se o teto de itens da rodada interrompeu no meio,
            // avançar last_sync_at faria a PRÓXIMA rodada (incremental,
            // corte a partir de agora) nunca mais alcançar o que ficou pra
            // trás na listagem — a mensagem de erro promete "continua de
            // onde parou", e só é verdade se o cursor não se mover daqui.
            if ($catalogoCompleto) {
                // O corte incremental da PRÓXIMA rodada é o instante em que ESTA
                // começou, e não o de agora: o que a origem alterou durante a
                // execução precisa entrar da próxima vez.
                $atualizacoes['last_sync_at'] = $startedAt;
            }

            $this->integrationModel->update($integration->id, $atualizacoes);
        } catch (AuthException $e) {
            // Credencial recusada: desliga o sync. Insistir de 30 em 30 minutos
            // com token inválido só empilha erro e pode virar bloqueio do lado
            // de lá. O tenant reabilita testando a conexão.
            $result->addError($e->getMessage());
            $this->runModel->finish($runId, IntegrationSyncRunModel::STATUS_ERROR, $result->toCounters(), $e->getMessage());
            $this->integrationModel->update($integration->id, [
                'is_active'                  => false,
                'status'                     => AccountIntegrationModel::STATUS_ERROR,
                'last_test_message'          => $e->getMessage(),
                'sync_priority_requested_at' => null,
            ]);
        } catch (RateLimitException $e) {
            // Credencial boa, só não é hora: não desliga nada, e o cursor fica
            // onde está para a próxima passada continuar.
            $result->addError($e->getMessage());
            $this->runModel->finish($runId, IntegrationSyncRunModel::STATUS_PARTIAL, $result->toCounters(), $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[IntegrationSync] Falha na integração ' . $integration->id . ': ' . $e->getMessage());
            $result->addError($e->getMessage());
            $this->runModel->finish($runId, IntegrationSyncRunModel::STATUS_ERROR, $result->toCounters(), $e->getMessage());
            $this->integrationModel->update($integration->id, [
                'status'                     => AccountIntegrationModel::STATUS_ERROR,
                'last_test_message'          => $e->getMessage(),
                'sync_priority_requested_at' => null,
            ]);
        } finally {
            cache()->delete($lockKey);
        }

        return $result;
    }

    // ------------------------------------------------------------- catálogo

    /**
     * @return bool true quando o catálogo foi percorrido até o fim (nenhum
     *              corte pelo teto de itens) — só nesse caso é seguro avançar
     *              o cursor incremental e pausar quem sumiu (ver run()).
     */
    private function consume(
        AccountIntegration $integration,
        IntegrationProviderInterface $connector,
        SyncResult $result,
        int $runId,
        bool $forceFull,
    ): bool {
        $accountId = (int) $integration->account_id;
        $provider  = (string) $integration->provider_code;
        $cursor    = SyncCursor::fromIntegration($integration, $forceFull);
        $settings  = $integration->settings();

        // Conta só o que custou uma busca de detalhe — não o total_fetched
        // (que inclui os "nada mudou" resolvidos na listagem, e é a métrica
        // que o resumo da rodada mostra pro tenant). Um catálogo de milhares
        // de itens, quase todos inalterados, não pode estourar o teto antes
        // de alcançar o punhado que de fato precisava de trabalho.
        $processados = 0;

        foreach ($connector->fetchCatalog($cursor, $settings) as $item) {
            if ($processados >= $this->maxItemsPerRun) {
                $result->addError('Limite de itens por rodada atingido; a próxima sincronização continua de onde parou.');

                return false;
            }

            $result->totalFetched++;

            try {
                $custouDetalhe = $this->syncItem($integration, $item, $result, $runId, $settings);
            } catch (AuthException | RateLimitException $e) {
                // Estas param a rodada inteira: não adianta seguir para o
                // próximo item se a credencial caiu ou o servidor pediu calma.
                throw $e;
            } catch (\Throwable $e) {
                // Um imóvel com dado sujo não pode derrubar o catálogo inteiro.
                $result->addError("Imóvel {$item->externalCode}: " . $e->getMessage());
                log_message('warning', sprintf(
                    '[IntegrationSync] conta %d, %s %s: %s',
                    $accountId,
                    $provider,
                    $item->externalId,
                    $e->getMessage()
                ));
                $custouDetalhe = true;
            }

            if ($custouDetalhe) {
                $processados++;
            }
        }

        return true;
    }

    /**
     * @return bool true quando o item custou uma busca de detalhe (conta
     *              contra o teto de itens da rodada em consume()); false no atalho
     *              barato de "nada mudou", que não devia consumir o teto de
     *              uma rodada só porque o catálogo inteiro foi listado.
     */
    private function syncItem(
        AccountIntegration $integration,
        CatalogItem $item,
        SyncResult $result,
        int $runId,
        array $settings,
    ): bool {
        $accountId = (int) $integration->account_id;
        $provider  = (string) $integration->provider_code;

        $ref = $this->refModel->findRef($accountId, $provider, $item->externalId);

        // Nada mudou na origem: marca como visto (para não ser pausado como
        // sumido) e segue sem gastar a requisição do detalhe.
        if ($ref !== null && $this->isUnchanged($ref, $item)) {
            $this->refModel->update($ref->id, [
                'last_synced_at'   => date('Y-m-d H:i:s'),
                'last_sync_run_id' => $runId,
            ]);
            $result->skipped++;

            return false;
        }

        $external = $item->resolve();

        if ($external === null) {
            // O conector não conseguiu montar um imóvel publicável (sem preço,
            // sem finalidade ativa). Se já existe aqui, pausa em vez de deixar
            // um anúncio inválido no ar.
            if ($ref !== null && $ref->property_id !== null) {
                $this->pauseProperty((int) $ref->property_id);
                $this->refModel->update($ref->id, [
                    'last_synced_at'   => date('Y-m-d H:i:s'),
                    'last_sync_run_id' => $runId,
                ]);
                $result->paused++;
            }

            return true;
        }

        if ($external->ignoreReason !== null) {
            // Item deliberadamente não importado (ex.: categoria ainda sem
            // de/para confirmado em /admin/integracoes/{code}/mapeamentos).
            // Não é erro nem "sumiu da origem" — se já existia um imóvel
            // publicado, o mapeamento que o sustentava mudou de baixo dele, e
            // ele pausa; se nunca existiu, só conta como ignorado.
            if ($ref !== null && $ref->property_id !== null) {
                $this->pauseProperty((int) $ref->property_id);
            }

            $result->ignored++;

            return true;
        }

        // Segunda barreira: o updatedAt pode ter mudado sem o conteúdo mudar
        // (o corretor abriu e salvou sem alterar nada).
        $hash = $external->contentHash();

        if ($ref !== null && $ref->payload_hash === $hash) {
            $this->refModel->update($ref->id, [
                'last_synced_at'      => date('Y-m-d H:i:s'),
                'last_sync_run_id'    => $runId,
                'external_updated_at' => $external->externalUpdatedAt,
            ]);
            $result->skipped++;

            return true;
        }

        $isNew = $ref === null;

        if ($isNew && $result->planLimitReached) {
            // Já estourou o plano nesta rodada: não adianta tentar de novo.
            return true;
        }

        try {
            $propertyId = $this->upsertProperty($integration, $external, $ref?->property_id, $result);
        } catch (\Throwable $e) {
            // Falha de validação (bairro/cidade ausentes, etc.): grava o
            // vínculo MESMO ASSIM, com property_id nulo e o motivo em
            // last_error. Sem isto, o item nunca ganha payload_hash nem
            // external_updated_at, e a próxima rodada não tem como saber que
            // ele já foi tentado — rebusca o detalhe, falha de novo, pra
            // sempre. Com o vínculo gravado, uma origem que não mudou nada
            // cai no atalho de "hash igual" (acima) e para de custar uma
            // busca de detalhe por rodada.
            $this->refModel->upsertRef([
                'property_id'         => $ref?->property_id,
                'account_id'          => $accountId,
                'provider_code'       => $provider,
                'external_id'         => $item->externalId,
                'external_code'       => $external->externalCode,
                'external_updated_at' => $external->externalUpdatedAt,
                'payload_hash'        => $hash,
                'last_synced_at'      => date('Y-m-d H:i:s'),
                'last_sync_run_id'    => $runId,
                'last_error'          => mb_substr($e->getMessage(), 0, 500),
            ]);

            throw $e;
        }

        if ($propertyId === null) {
            return true;
        }

        $this->refModel->upsertRef([
            'property_id'         => $propertyId,
            'account_id'          => $accountId,
            'provider_code'       => $provider,
            // A chave do vínculo é a mesma usada no findRef() lá em cima
            // ($item->externalId, vindo da LISTAGEM) — não $external->externalId
            // (o mapper prefere o id do DETALHE, e só cai pro da listagem
            // quando o detalhe não trouxe 'id'). Os dois costumam coincidir,
            // mas gravar com uma chave e buscar com outra faria a próxima
            // rodada não encontrar o vínculo, criar um imóvel duplicado, e
            // órfão o primeiro.
            'external_id'         => $item->externalId,
            'external_code'       => $external->externalCode,
            'external_updated_at' => $external->externalUpdatedAt,
            'payload_hash'        => $hash,
            'last_synced_at'      => date('Y-m-d H:i:s'),
            'last_sync_run_id'    => $runId,
            // Limpa um last_error de uma tentativa anterior que falhou: este
            // upsert só chega aqui depois de upsertProperty() ter funcionado.
            'last_error'          => null,
        ]);

        $isNew ? $result->created++ : $result->updated++;

        if (! empty($settings['import_images'])) {
            $result->images += $this->syncImages($propertyId, $external, $result);
        }

        return true;
    }

    /**
     * A origem informa a mesma data de alteração já registrada aqui?
     *
     * Sem data na origem não dá para concluir nada — segue para o detalhe.
     */
    private function isUnchanged(object $ref, CatalogItem $item): bool
    {
        if ($item->externalUpdatedAt === null || empty($ref->external_updated_at)) {
            return false;
        }

        $gravado = $ref->external_updated_at;

        if ($gravado instanceof \CodeIgniter\I18n\Time) {
            $gravado = $gravado->toDateTimeString();
        }

        return strtotime((string) $gravado) === strtotime($item->externalUpdatedAt);
    }

    /**
     * Cria ou atualiza o imóvel.
     *
     * isStaff = false de propósito: o import passa pelas mesmas travas do
     * cliente, incluindo PropertyService::GUARDED_FIELDS. Uma plataforma
     * externa não pode marcar imóvel como verificado ou destacado.
     *
     * partialUpdate = true para não zerar os booleanos que a origem não manda.
     */
    private function upsertProperty(
        AccountIntegration $integration,
        ExternalProperty $external,
        ?int $existingId,
        SyncResult $result,
    ): ?int {
        $data = $external->fields;

        $data['account_id']         = (int) $integration->account_id;
        $data['source']             = 'integration:' . $integration->provider_code;
        $data['external_synced_at'] = date('Y-m-d H:i:s');

        // O mapper sempre devolve um `status` (initial_status da configuração,
        // default DRAFT) porque ele não sabe se está mapeando uma criação ou
        // uma atualização. Numa atualização, esse valor sobrescreveria
        // silenciosamente qualquer publicação manual do tenant a cada rodada
        // — `status` foi tirado de MANAGED_FIELDS (IntegrationService) por
        // isso mesmo, e aqui é onde a exceção se aplica de fato: só entra na
        // criação.
        if ($existingId !== null) {
            unset($data['status']);
        }

        $this->fillMissingCoordinates($data, $existingId, $result);

        // trySaveProperty NÃO valida os campos — o model só valida account_id.
        // Quem chama é responsável por validar antes, como faz o
        // PropertyImportService. Sem isto, um imóvel sem cidade ou sem preço
        // entraria no catálogo e quebraria a busca do portal.
        $validation = $this->propertyService->validatePropertyData($data, $existingId !== null);

        if (! $validation['valid']) {
            throw new \RuntimeException(implode('; ', array_map(
                static fn ($campo, $erro) => "{$campo}: {$erro}",
                array_keys($validation['errors']),
                $validation['errors']
            )));
        }

        $saved = $this->propertyService->trySaveProperty($data, $existingId, false, $existingId !== null);

        if (! empty($saved['success'])) {
            return (int) ($saved['data']->id ?? $existingId);
        }

        $message = $saved['message'] ?? 'não foi possível salvar';

        // Limite do plano estourado tem tratamento próprio: a rodada continua
        // atualizando o que já existe, só para de criar. Vira PARTIAL, com uma
        // mensagem que o tenant entende (e que serve de upsell).
        if ($existingId === null && $this->looksLikePlanLimit($message, $saved['errors'] ?? [])) {
            $result->planLimitReached = true;

            return null;
        }

        $errors = $saved['errors'] ?? [];
        $detail = $errors === [] ? $message : implode('; ', array_map(
            static fn ($k, $v) => "{$k}: {$v}",
            array_keys($errors),
            $errors
        ));

        throw new \RuntimeException($detail);
    }

    private function looksLikePlanLimit(string $message, array $errors): bool
    {
        $haystack = mb_strtolower($message . ' ' . implode(' ', $errors));

        return str_contains($haystack, 'limite') || str_contains($haystack, 'plano');
    }

    /**
     * Geocodifica quando o item ainda não tem coordenada — nem no payload
     * desta rodada, nem já salva de uma rodada anterior. Sem essa segunda
     * checagem, toda ATUALIZAÇÃO de um imóvel que já tem lat/lng geocodificada
     * bateria a Nominatim de novo, porque o mapper não devolve coordenada
     * nenhuma quando a origem não fornece (e a origem, no caso da Giusti,
     * nunca fornece).
     */
    private function fillMissingCoordinates(array &$data, ?int $existingId, SyncResult $result): void
    {
        if (isset($data['latitude'], $data['longitude'])) {
            return;
        }

        if ($existingId !== null) {
            $atual = $this->propertyModel->find($existingId);

            if ($atual !== null && $atual->latitude !== null && $atual->longitude !== null) {
                return;
            }
        }

        if ($this->geocodedThisRun >= self::MAX_GEOCODE_PER_RUN) {
            return;
        }

        $cidade = trim((string) ($data['cidade'] ?? ''));

        if ($cidade === '') {
            return;
        }

        $this->geocodedThisRun++;

        $coordenadas = $this->geocoder->geocode([
            'rua'    => $data['rua'] ?? null,
            'numero' => $data['numero'] ?? null,
            'bairro' => $data['bairro'] ?? null,
            'cidade' => $data['cidade'] ?? null,
            'estado' => $data['estado'] ?? null,
        ]);

        if ($coordenadas === null) {
            return;
        }

        $data['latitude']  = $coordenadas['lat'];
        $data['longitude'] = $coordenadas['lng'];
        $result->geocoded++;
    }

    /**
     * Baixa as imagens que ainda não existem.
     *
     * addMediaFromUrl deduplica por sha256(url) — como a URL do CDN do Simob é
     * estável, uma segunda rodada não rebaixa nada.
     */
    private function syncImages(int $propertyId, ExternalProperty $external, SyncResult $result): int
    {
        $baixadas = 0;

        foreach ($external->images as $image) {
            try {
                $res = $this->propertyService->addMediaFromUrl($image->url, $propertyId, $image->toMediaOptions());

                if (! empty($res['success']) && empty($res['skipped'])) {
                    $baixadas++;
                }
            } catch (\Throwable $e) {
                // Foto que não baixa não invalida o imóvel.
                log_message('warning', "[IntegrationSync] imagem do imóvel {$propertyId} falhou: " . $e->getMessage());
            }
        }

        return $baixadas;
    }

    // -------------------------------------------------------------- sumidos

    /**
     * Pausa o que não apareceu nesta rodada.
     *
     * Nunca deleta: o imóvel pode ter leads, visitas e histórico atrelados, e
     * o sumiço pode ser temporário (o corretor tirou do portal por um dia).
     *
     * Só roda em sincronização COMPLETA. Numa incremental o catálogo devolvido
     * é só o que mudou, então "não apareceu" não significa "sumiu" — pausar
     * aqui derrubaria o catálogo inteiro do tenant.
     */
    /**
     * "Sumiu do catálogo" só pode ser concluído quando a rodada varreu o
     * catálogo INTEIRO — incremental por natureza só vê quem mudou, e pausar
     * com base nisso pausaria todo o resto do catálogo por engano.
     *
     * O antigo `empty($integration->last_sync_at)` capturava só a PRIMEIRA
     * rodada de todas; qualquer `--full` depois da primeira nunca detectava
     * sumido. E o corte por `$result->errors > 0` — pensado pra não confiar
     * numa varredura "incompleta" — deixou de fazer sentido depois que item
     * com erro de validação passou a gravar vínculo mesmo assim (ver
     * upsertProperty): ele já é contado como "visto" pela rodada, erro isolado
     * de item não impede mais a conclusão da rodada completa.
     */
    private function pauseVanished(AccountIntegration $integration, SyncResult $result, int $runId, bool $forceFull): void
    {
        $foiCompleta = $forceFull || empty($integration->last_sync_at);

        if (! $foiCompleta) {
            return;
        }

        $stale = $this->refModel->staleProperties(
            (int) $integration->account_id,
            (string) $integration->provider_code,
            $runId
        );

        foreach ($stale as $propertyId) {
            if ($this->pauseProperty($propertyId)) {
                $result->paused++;
            }
        }
    }

    private function pauseProperty(int $propertyId): bool
    {
        $property = $this->propertyModel->find($propertyId);

        if ($property === null || $property->status === 'PAUSED') {
            return false;
        }

        return (bool) $this->propertyModel->update($propertyId, [
            'status'             => 'PAUSED',
            'external_synced_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
