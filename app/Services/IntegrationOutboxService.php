<?php

namespace App\Services;

use App\Entities\IntegrationOutboxItem;
use App\Libraries\Integrations\Exceptions\AuthException;
use App\Libraries\Integrations\IntegrationProviderInterface;
use App\Models\AccountIntegrationModel;
use App\Models\IntegrationOutboxModel;
use App\Models\PropertyExternalRefModel;
use App\Models\PropertyModel;

/**
 * Via de volta da integração: leva o lead capturado no portal para o CRM da
 * plataforma de origem.
 *
 * O enfileiramento acontece no caminho de criação do lead e NUNCA pode
 * derrubá-lo — se algo aqui falhar, o lead já está salvo no Habitaweb e o
 * envio simplesmente não acontece. Perder o registro do lead para tentar
 * notificar um terceiro seria trocar o certo pelo duvidoso.
 */
class IntegrationOutboxService
{
    public function __construct(
        private ?IntegrationOutboxModel $outbox = null,
        private ?PropertyExternalRefModel $refModel = null,
        private ?AccountIntegrationModel $integrationModel = null,
        private ?PropertyModel $propertyModel = null,
        private ?IntegrationService $integrationService = null,
    ) {
        $this->outbox             ??= model(IntegrationOutboxModel::class);
        $this->refModel           ??= model(PropertyExternalRefModel::class);
        $this->integrationModel   ??= model(AccountIntegrationModel::class);
        $this->propertyModel      ??= model(PropertyModel::class);
        $this->integrationService ??= new IntegrationService();
    }

    // ----------------------------------------------------------- enfileirar

    /**
     * Enfileira um lead para o CRM da origem, se o imóvel veio de lá.
     *
     * Silencioso por natureza: lead de imóvel cadastrado à mão não tem para
     * onde ir, e isso não é erro.
     *
     * @return int|null id do item na fila, ou null se não havia o que enviar
     */
    public function enqueueLead(object $lead): ?int
    {
        try {
            return $this->buildAndEnqueue($lead);
        } catch (\Throwable $e) {
            // O lead JÁ ESTÁ salvo. Falhar aqui não pode propagar para o
            // visitante que preencheu o formulário.
            log_message('error', '[IntegrationOutbox] Falha ao enfileirar lead ' . ($lead->id ?? '?') . ': ' . $e->getMessage());

            return null;
        }
    }

    private function buildAndEnqueue(object $lead): ?int
    {
        $propertyId = (int) ($lead->property_id ?? 0);

        if ($propertyId === 0) {
            return null;
        }

        $ref = $this->refModel->findByProperty($propertyId);

        if ($ref === null) {
            return null;
        }

        $integration = $this->integrationModel->findForAccount(
            (int) $ref->account_id,
            (string) $ref->provider_code
        );

        // Gate por CONEXÃO (status), não por is_active: pausar o sync
        // automático do CATÁLOGO (o toggle da tela) não pode silenciosamente
        // parar de devolver leads pro CRM da imobiliária — são dois fluxos
        // independentes, e um tenant que pausou a importação de imóveis
        // ainda quer que o lead volte pra ele vender.
        if ($integration === null || ! $integration->isConnected()) {
            return null;
        }

        $property = $this->propertyModel->find($propertyId);

        if ($property === null) {
            return null;
        }

        $payload = [
            'lead' => [
                'nome'       => $lead->nome_visitante ?? null,
                'email'      => $lead->email_visitante ?? null,
                'telefone'   => $lead->telefone_visitante ?? null,
                'mensagem'   => $lead->mensagem ?? null,
                'url_imovel' => site_url('imovel/' . $propertyId),
            ],
            'property' => [
                'external_id'   => $ref->external_id,
                'external_code' => $ref->external_code,
                'tipo_negocio'  => $property->tipo_negocio,
                'cidade'        => $property->cidade,
                'bairro'        => $property->bairro,
                'estado'        => $property->estado,
                // A categoria do lado de lá é obrigatória no /crm_interesse/create.
                'external_categoria_id' => $this->externalCategoryId($integration, $property),
            ],
            'options' => [],
        ];

        return $this->outbox->enqueue(
            (int) $integration->id,
            IntegrationOutboxModel::EVENT_LEAD_CREATED,
            (int) $lead->id,
            $payload
        );
    }

    /**
     * Descobre o id de categoria da origem a partir do tipo_imovel daqui.
     *
     * É o caminho inverso do de/para de importação: procura o mapeamento cujo
     * target_value bate com o tipo do imóvel. Sem isso o interesse chega ao
     * SimobCRM sem categoria, que é campo obrigatório lá.
     */
    private function externalCategoryId(object $integration, object $property): ?string
    {
        $mappings = $this->integrationService->mappingsOf(
            $integration,
            \App\Models\IntegrationMappingModel::KIND_CATEGORY
        );

        foreach ($mappings as $mapping) {
            if ($mapping->target_value === $property->tipo_imovel) {
                return (string) $mapping->external_id;
            }
        }

        return null;
    }

    // -------------------------------------------------------------- entregar

    /**
     * Processa os itens vencidos da fila.
     *
     * @return array{processed:int, sent:int, retried:int, failed:int}
     */
    public function processDue(int $limit = 50): array
    {
        $stats = ['processed' => 0, 'sent' => 0, 'retried' => 0, 'failed' => 0];

        foreach ($this->outbox->due($limit) as $item) {
            $stats['processed']++;

            $outcome = $this->deliver($item);
            $stats[$outcome]++;
        }

        return $stats;
    }

    /** @return 'sent'|'retried'|'failed' */
    public function deliver(IntegrationOutboxItem $item): string
    {
        $integration = $this->integrationModel->find($item->account_integration_id);

        if ($integration === null) {
            $this->outbox->markFailed((int) $item->id, IntegrationOutboxModel::MAX_ATTEMPTS, 'Integração removida.');

            return 'failed';
        }

        // Integração desligada não é falha do item: quando o tenant religar, o
        // lead ainda estará aqui esperando. Reagenda em vez de queimar tentativa.
        if (! $integration->is_active) {
            $this->outbox->update($item->id, [
                'next_attempt_at' => date('Y-m-d H:i:s', time() + 3600),
                'last_error'      => 'Integração desativada; aguardando reativação.',
            ]);

            return 'retried';
        }

        try {
            $connector = $this->integrationService->makeConnector($integration);

            if (! $connector->supports(IntegrationProviderInterface::CAP_PUSH_LEADS)) {
                $this->outbox->markFailed((int) $item->id, IntegrationOutboxModel::MAX_ATTEMPTS, 'Conector não envia leads.');

                return 'failed';
            }

            $result = $connector->pushLead($item->payloadArray());
        } catch (AuthException $e) {
            // Credencial recusada: não adianta gastar as tentativas restantes.
            // Falha o item de uma vez e deixa o botão de reenvio para depois de
            // o tenant arrumar a credencial.
            $this->outbox->markFailed((int) $item->id, IntegrationOutboxModel::MAX_ATTEMPTS, $e->getMessage());

            return 'failed';
        } catch (\Throwable $e) {
            return $this->outbox->markFailed((int) $item->id, (int) $item->attempts, $e->getMessage())
                ? 'retried'
                : 'failed';
        }

        if ($result->success) {
            $externalRef = $this->extractRef($result->details);

            // Log só quando NÃO reconhecemos um id (não em todo envio — isto
            // rodaria a cada minuto, sempre): é o único jeito de descobrir o
            // formato real de resposta de um conector novo (ou de uma versão
            // nova da API) sem abrir uma issue pedindo o payload cru — sem
            // token, sem header, só o corpo já decodificado.
            if ($externalRef === null) {
                log_message('debug', sprintf(
                    '[IntegrationOutbox] item %d enviado sem id reconhecido na resposta: %s',
                    $item->id,
                    json_encode($result->details, JSON_UNESCAPED_UNICODE)
                ));
            }

            $this->outbox->markSent((int) $item->id, $externalRef);

            return 'sent';
        }

        return $this->outbox->markFailed((int) $item->id, (int) $item->attempts, $result->message)
            ? 'retried'
            : 'failed';
    }

    /**
     * external_ref é VARCHAR(120) — sem um id reconhecido, guarda os
     * primeiros 120 caracteres da resposta em vez de gravar null. É pouco
     * pra diagnosticar tudo, mas já é mais do que "enviado, sem rastro
     * nenhum do que a origem devolveu" quando o webhook de log (acima) não
     * está visível pra quem está olhando o painel, só o banco.
     */
    private function extractRef(array $details): ?string
    {
        $response = $details['response'] ?? null;

        if (is_array($response)) {
            $id = $response['id'] ?? $response[0]['id'] ?? null;

            if ($id !== null) {
                return (string) $id;
            }

            return $response === [] ? null : mb_substr(json_encode($response, JSON_UNESCAPED_UNICODE), 0, 120);
        }

        return is_scalar($response) ? mb_substr((string) $response, 0, 120) : null;
    }

    /** Estado do envio de um lead, para o selo na tela. */
    public function leadStatus(int $leadId): ?IntegrationOutboxItem
    {
        return $this->outbox->statusForLead($leadId);
    }

    public function retry(int $outboxId): bool
    {
        return $this->outbox->retry($outboxId);
    }
}
