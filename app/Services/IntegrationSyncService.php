<?php

namespace App\Services;

use App\Entities\AccountIntegration;
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
    private const MAX_ITEMS_PER_RUN = 2000;

    /** Trava para não haver duas rodadas simultâneas da mesma integração. */
    private const LOCK_TTL = 1800;

    public function __construct(
        private ?IntegrationService $integrationService = null,
        private ?PropertyService $propertyService = null,
        private ?PropertyExternalRefModel $refModel = null,
        private ?AccountIntegrationModel $integrationModel = null,
        private ?IntegrationSyncRunModel $runModel = null,
        private ?PropertyModel $propertyModel = null,
    ) {
        $this->integrationService ??= new IntegrationService();
        $this->propertyService    ??= new PropertyService();
        $this->refModel           ??= model(PropertyExternalRefModel::class);
        $this->integrationModel   ??= model(AccountIntegrationModel::class);
        $this->runModel           ??= model(IntegrationSyncRunModel::class);
        $this->propertyModel      ??= model(PropertyModel::class);
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

            $this->consume($integration, $connector, $result, $runId, $forceFull);
            $this->pauseVanished($integration, $result, $runId);

            $this->runModel->finish($runId, $result->status(), $result->toCounters(), $result->errorSummary());

            // O corte incremental da PRÓXIMA rodada é o instante em que ESTA
            // começou, e não o de agora: o que a origem alterou durante a
            // execução precisa entrar da próxima vez.
            $this->integrationModel->update($integration->id, [
                'last_sync_at'               => $startedAt,
                'status'                     => AccountIntegrationModel::STATUS_CONNECTED,
                // Pedido de "sincronizar agora" foi atendido nesta rodada.
                'sync_priority_requested_at' => null,
            ]);
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

    private function consume(
        AccountIntegration $integration,
        IntegrationProviderInterface $connector,
        SyncResult $result,
        int $runId,
        bool $forceFull,
    ): void {
        $accountId = (int) $integration->account_id;
        $provider  = (string) $integration->provider_code;
        $cursor    = SyncCursor::fromIntegration($integration, $forceFull);
        $settings  = $integration->settings();

        foreach ($connector->fetchCatalog($cursor, $settings) as $item) {
            if ($result->totalFetched >= self::MAX_ITEMS_PER_RUN) {
                $result->addError('Limite de itens por rodada atingido; a próxima sincronização continua de onde parou.');
                break;
            }

            $result->totalFetched++;

            try {
                $this->syncItem($integration, $item, $result, $runId, $settings);
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
            }
        }
    }

    private function syncItem(
        AccountIntegration $integration,
        CatalogItem $item,
        SyncResult $result,
        int $runId,
        array $settings,
    ): void {
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

            return;
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

            return;
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

            return;
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

            return;
        }

        $isNew = $ref === null;

        if ($isNew && $result->planLimitReached) {
            // Já estourou o plano nesta rodada: não adianta tentar de novo.
            return;
        }

        $propertyId = $this->upsertProperty($integration, $external, $ref?->property_id, $result);

        if ($propertyId === null) {
            return;
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
        ]);

        $isNew ? $result->created++ : $result->updated++;

        if (! empty($settings['import_images'])) {
            $result->images += $this->syncImages($propertyId, $external, $result);
        }
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
    private function pauseVanished(AccountIntegration $integration, SyncResult $result, int $runId): void
    {
        $foiCompleta = empty($integration->last_sync_at);

        if (! $foiCompleta || $result->errors > 0) {
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
