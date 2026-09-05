<?php

namespace App\Services;

use App\Entities\Property;
use App\Models\PropertyModel;
use App\Models\PlanModel;
use App\Models\SubscriptionModel;
use CodeIgniter\Config\Factories;
use App\Services\CurationService;

class PropertyService
{
    /**
     * Campos que constam em PropertyModel::$allowedFields mas que o cliente
     * NUNCA pode escrever diretamente: uns são produto pago (destaque/turbo),
     * outros são sinal de confiança exibido ao comprador (selo verificado), e
     * os contadores são métricas internas. Só fluxo interno (isStaff) escreve.
     */
    public const GUARDED_FIELDS = [
        'is_destaque',
        'highlight_level',
        'highlight_expires_at',
        'is_verified',
        'verification_status',
        'visitas_count',
        'leads_count',
        'score_qualidade',
        'moderation_status',
    ];

    protected PropertyModel $propertyModel;
    protected SubscriptionModel $subscriptionModel;
    protected PlanModel $planModel;
    protected CurationService $curationService;
    protected PublicPropertyVisibilityService $publicVisibility;

    /**
     * Baixador de imagens remotas. Injetável para que os testes possam exercer
     * o pipeline de ingestão por URL sem depender de rede externa — a guarda de
     * SSRF (correta) bloquearia até um servidor local de teste em 127.0.0.1.
     */
    protected ?\App\Libraries\Media\RemoteImageFetcher $imageFetcher = null;

    public function setImageFetcher(\App\Libraries\Media\RemoteImageFetcher $fetcher): void
    {
        $this->imageFetcher = $fetcher;
    }

    protected function imageFetcher(): \App\Libraries\Media\RemoteImageFetcher
    {
        return $this->imageFetcher ??= new \App\Libraries\Media\RemoteImageFetcher();
    }

    public function __construct()
    {
        $this->propertyModel     = Factories::models(PropertyModel::class);
        $this->subscriptionModel = Factories::models(SubscriptionModel::class);
        $this->planModel         = Factories::models(PlanModel::class);
        $this->curationService   = new CurationService();
        $this->publicVisibility  = new PublicPropertyVisibilityService();
    }

    /**
     * Valida o payload de um imóvel ANTES de tentar persistir.
     *
     * PropertyModel::$validationRules só valida account_id — todas as demais
     * colunas NOT NULL (titulo, cidade, bairro, tipo_negocio, tipo_imovel)
     * dependiam da constraint do banco, o que devolvia ao parceiro um genérico
     * "Falha na persistência dos dados." sem dizer qual campo faltou. Aqui a
     * mensagem é por campo.
     *
     * ImportController já chamava PropertyService::validatePropertyData() — mas
     * o método nunca existiu, então todo import com validate_only=true morria
     * num Error não capturado (500).
     *
     * @param bool $isUpdate Em atualização, só valida o que veio no payload.
     * @return array{valid: bool, errors: array<string, string>}
     */
    public function validatePropertyData(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        $required = ['titulo', 'tipo_negocio', 'tipo_imovel', 'preco', 'cidade', 'bairro'];

        foreach ($required as $field) {
            $missing = $isUpdate
                ? (array_key_exists($field, $data) && ($data[$field] === null || trim((string) $data[$field]) === ''))
                : (! isset($data[$field]) || trim((string) $data[$field]) === '');

            if ($missing) {
                $errors[$field] = "O campo '{$field}' é obrigatório.";
            }
        }

        if (isset($data['titulo']) && mb_strlen(trim((string) $data['titulo'])) > 255) {
            $errors['titulo'] = 'O título deve ter no máximo 255 caracteres.';
        }

        if (isset($data['tipo_negocio']) && $data['tipo_negocio'] !== '') {
            $allowed = ['VENDA', 'ALUGUEL', 'TEMPORADA', 'VENDA_ALUGUEL'];
            if (! in_array(strtoupper((string) $data['tipo_negocio']), $allowed, true)) {
                $errors['tipo_negocio'] = 'tipo_negocio deve ser um de: ' . implode(', ', $allowed) . '.';
            }
        }

        if (isset($data['preco']) && $data['preco'] !== '' && $data['preco'] !== null) {
            $preco = is_string($data['preco'])
                ? (float) str_replace(['.', ','], ['', '.'], trim($data['preco']))
                : (float) $data['preco'];

            if ($preco < 0) {
                $errors['preco'] = 'O preço não pode ser negativo.';
            }
        }

        if (isset($data['status']) && $data['status'] !== '') {
            $allowed = ['DRAFT', 'ACTIVE', 'PAUSED', 'SOLD'];
            if (! in_array(strtoupper((string) $data['status']), $allowed, true)) {
                $errors['status'] = 'status deve ser um de: ' . implode(', ', $allowed) . '.';
            }
        }

        if (isset($data['estado']) && $data['estado'] !== '' && mb_strlen(trim((string) $data['estado'])) !== 2) {
            $errors['estado'] = 'estado deve ser a sigla de 2 letras da UF (ex.: SP).';
        }

        foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $field => [$min, $max]) {
            if (isset($data[$field]) && $data[$field] !== '' && $data[$field] !== null) {
                $value = (float) str_replace(',', '.', (string) $data[$field]);
                if ($value < $min || $value > $max) {
                    $errors[$field] = "{$field} deve estar entre {$min} e {$max}.";
                }
            }
        }

        return ['valid' => $errors === [], 'errors' => $errors];
    }

    /**
     * Tenta salvar (criar ou atualizar) um imóvel.
     * Valida limites do plano se o usuário estiver ativando o imóvel.
     *
     * @param array $data
     * @param int|null $id
     * @param bool $isStaff Se true, ignora limites de plano.
     * @return array
     */
    /**
     * @param bool $fromSync true SÓ quando quem chama é o próprio sync de
     *                       integração (IntegrationSyncService) — é o único
     *                       caminho autorizado a escrever os campos que ele
     *                       mesmo gerencia. Qualquer outro chamador (admin,
     *                       API v1) tem esses campos removidos do payload
     *                       silenciosamente, com o nome deles devolvido em
     *                       `ignored_fields` pra quem chamou avisar o usuário.
     */
    public function trySaveProperty(array $data, ?int $id = null, bool $isStaff = false, bool $partialUpdate = false, bool $fromSync = false): array
    {
        try {
            // 0. Load or New
            $property = $id ? $this->propertyModel->find($id) : new Property();

            if ($id && !$property) {
                return [
                    'success' => false,
                    'data'    => null,
                    'errors'  => [],
                    'message' => 'Imóvel não encontrado.',
                ];
            }

            // 0a. IMÓVEL ESPELHADO DE INTEGRAÇÃO
            // Centralizado aqui porque PropertyController::update() era o
            // ÚNICO lugar que aplicava essa guarda — a API v1
            // (Api\V1\PropertyController::update) e o delete de ambos
            // reescreviam ou apagavam um imóvel gerenciado sem check nenhum.
            // A origem é a fonte da verdade pra estes campos; a próxima
            // rodada de sync sobrescreveria a edição de qualquer forma, o
            // que é pior que recusar agora.
            $ignoredFields = [];

            if ($id && !$fromSync && model(\App\Models\PropertyExternalRefModel::class)->isManaged($id)) {
                foreach (\App\Services\IntegrationService::MANAGED_FIELDS as $field) {
                    if (array_key_exists($field, $data)) {
                        $ignoredFields[] = $field;
                        unset($data[$field]);
                    }
                }
            }

            // 0b. CAMPOS PRIVILEGIADOS
            // PropertyModel::$allowedFields inclui campos que valem dinheiro
            // (destaque/turbo) ou confiança (selo verificado). Como nada os
            // removia do payload, um POST /api/v1/properties com
            // {"is_destaque":true,"highlight_level":3,"is_verified":true} dava
            // posicionamento pago e selo de verificado de graça. Só fluxo
            // interno (isStaff) pode escrevê-los.
            if (!$isStaff) {
                foreach (self::GUARDED_FIELDS as $guarded) {
                    unset($data[$guarded]);
                }
            }

            // 1. Sanitization (PT-BR -> Decimal)
            $numericFields = [
                'preco', 'valor_condominio', 'iptu', 'renda_mensal_estimada', 'area_total',
                'area_construida', 'area_privativa', 'latitude', 'longitude', 'client_id', 'user_id_responsavel',
                'quartos', 'banheiros', 'vagas', 'suites', 'highlight_level'
            ];
            foreach ($numericFields as $field) {
                if (isset($data[$field]) && is_string($data[$field])) {
                    $trimmed = trim($data[$field]);
                    if ($trimmed === '') {
                        $data[$field] = null;
                    } else {
                        if ($field === 'latitude' || $field === 'longitude') {
                            $data[$field] = (float) str_replace(',', '.', $trimmed);
                        } else {
                            $data[$field] = (float) str_replace(['.', ','], ['', '.'], $trimmed);
                        }
                    }
                }
            }

            // 2. Map Booleans
            // No formulário do admin, um checkbox desmarcado simplesmente não é
            // enviado — por isso ausência precisa virar false ali.
            // Já numa atualização parcial via API (PATCH/import), ausência
            // significa "não mexa neste campo": forçar false apagaria
            // mobiliado/aceita_pets/etc. de quem mandou só {"preco": 500000}.
            $booleanFields = [
                'is_destaque', 'is_novo', 'is_exclusivo', 'aceita_pets', 'mobiliado',
                'semimobiliado', 'is_desocupado', 'is_locado', 'indicado_investidor',
                'indicado_primeira_moradia', 'indicado_temporada'
            ];
            $truthy = ['1', 1, true, 'true', 'TRUE', 'on', 'sim', 't'];
            foreach ($booleanFields as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = in_array($data[$field], $truthy, true) || $data[$field] === true;
                } elseif (!$partialUpdate) {
                    $data[$field] = false;
                }
            }

            // 3. FILL ENTITY
            $property->fill($data);

            // 4. PLAN LIMITS
            // Trava por linha (accounts.id) dentro de uma transação explícita para o
            // limite de plano valer sob concorrência - substituiu um advisory lock do
            // Postgres (pg_advisory_lock), que é preso à CONEXÃO FÍSICA, não à
            // transação: sob connection pooling em modo "transação" (ex.: PgBouncer),
            // o lock, a checagem de limite e o save poderiam rodar em conexões físicas
            // diferentes, e o lock pararia de proteger qualquer coisa - silenciosamente,
            // só sob concorrência real. SELECT ... FOR UPDATE dentro de
            // transStart()/transComplete() é correto sob qualquer modo de pooling
            // porque o tempo de vida do lock é o da transação, não o da conexão.
            $planDb = \Config\Database::connect();
            $planLockActive = false;

            // 4a. Teto de acumulação em rascunho.
            // O limite do plano (limite_imoveis_ativos) só é avaliado quando o
            // imóvel está ACTIVE — o que é semanticamente correto, mas deixava
            // uma conta sem assinatura válida despejar milhares de rascunhos
            // pelo import em lote, sem passar por checagem nenhuma. Aplicado só
            // na CRIAÇÃO, para não travar edição do que já existe.
            if ($id === null && !$isStaff && $property->status !== 'ACTIVE') {
                $storageCheck = $this->checkDraftQuota((int) $property->account_id);
                if (!$storageCheck['allowed']) {
                    return [
                        'success' => false,
                        'data'    => $property,
                        'errors'  => ['limit' => $storageCheck['message']],
                        'message' => $storageCheck['message'],
                    ];
                }
            }

            if ($property->status === 'ACTIVE' && !$isStaff) {
                if ($planDb->DBDriver === 'Postgre') {
                    $planDb->transStart();
                    $planDb->query('SELECT id FROM accounts WHERE id = ? FOR UPDATE', [(int) $property->account_id]);
                    $planLockActive = true;
                }

                $limitCheck = $this->checkPlanLimits((int)$property->account_id, $id, $isStaff);
                if (!$limitCheck['allowed']) {
                    if ($planLockActive) {
                        $planDb->transRollback();
                    }
                    return [
                        'success' => false,
                        'data'    => $property,
                        'errors'  => ['limit' => $limitCheck['message']],
                        'message' => 'Não foi possível ativar o imóvel. Verifique seu plano.',
                    ];
                }
            }

            // 5. CURATION & SIGNATURE
            // Signature check (Freshly calculated from current attributes)
            $property->duplicate_signature = $this->curationService->calculateSignature($property->toArray());
            $duplicate = $this->curationService->findDuplicate($property->duplicate_signature, $id);
            
            // Runs quality/moderation logic
            $this->curationService->validateProperty($property);
            
            if ($duplicate) {
                $warnings = $property->quality_warnings ?? [];
                if (!in_array('possible_duplicate', $warnings)) {
                    $warnings[] = 'possible_duplicate';
                    $property->quality_warnings = $warnings;
                    $property->moderation_status = 'PENDING_REVIEW';
                }
            }

            // 6. SAVE
            if (!$this->propertyModel->save($property)) {
                if ($planLockActive) {
                    $planDb->transRollback();
                }
                log_message('error', '[PropertyService] SAVE ERROR: ' . json_encode($this->propertyModel->errors()));
                return [
                    'success' => false,
                    'data'    => $property,
                    'errors'  => $this->propertyModel->errors(),
                    'message' => 'Falha na persistência dos dados.',
                ];
            }

            // Persistido dentro da mesma transação da checagem de limite: agora sim
            // pode liberar a trava, via commit (a contagem do limite já reflete este imóvel).
            if ($planLockActive) {
                $planDb->transComplete();
            }

            $savedId = $id ?? $this->propertyModel->getInsertID();

            // 7. POST-SAVE SYNC (Features, Cache, Scores)
            // FEATURES
            $featureModel = Factories::models(\App\Models\PropertyFeatureModel::class);
            $featureModel->where('property_id', $savedId)->delete();
            if (isset($data['features']) && is_array($data['features'])) {
                foreach ($data['features'] as $key) {
                    $featureModel->insert(['property_id' => $savedId, 'chave' => $key]);
                }
            }
            
            // CACHE
            PublicPropertyVisibilityService::invalidateCaches();

            // RANKING (Async update score)
            $rankingService = service('rankingService');
            $rankingService->updateScore($savedId);

            return [
                'success'        => true,
                'property_id'    => (int) $savedId,
                'data'           => $this->propertyModel->find($savedId),
                'errors'         => [],
                'message'        => 'Imóvel salvo com sucesso.',
                'ignored_fields' => $ignoredFields,
            ];

        } catch (\Exception $e) {
            if (!empty($planLockActive)) {
                $planDb->transRollback();
            }
            log_message('error', '[PropertyService] EXCEPTION: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return [
                'success' => false,
                'data'    => null,
                'errors'  => ['exception' => $e->getMessage()],
                'message' => 'Erro interno ao processar imóvel.',
            ];
        }
    }

    /**
     * Verifica se a conta pode ter mais um imóvel ativo.
     *
     * @param int $accountId
     * @param int|null $currentPropertyId Se for atualização, desconsidera o próprio imóvel na contagem.
     * @return bool
     */
    /**
     * Verifica limites do plano. Retorna array ['allowed' => bool, 'message' => string]
     */
    /**
     * Verifica limites do plano. Retorna array ['allowed' => bool, 'message' => string]
     */
    /**
     * Teto de imóveis em rascunho/pausados por conta.
     *
     * Não substitui o limite do plano (que vale para imóveis ATIVOS): serve só
     * para impedir que uma conta acumule indefinidamente registros não
     * publicados via import em lote. Contas sem assinatura em dia ficam com um
     * teto baixo.
     *
     * @return array{allowed: bool, message?: string}
     */
    public function checkDraftQuota(int $accountId): array
    {
        $subscription = $this->subscriptionModel
            ->where('account_id', $accountId)
            ->whereIn('status', ['ACTIVE', 'TRIAL'])
            ->orderBy('id', 'DESC')
            ->first();

        $plan  = $subscription ? $this->planModel->find($subscription->plan_id) : null;
        $limit = $plan->limite_imoveis_ativos ?? null;

        // Sem assinatura ativa: teto baixo. Plano ilimitado: teto generoso.
        // Caso normal: cinco vezes a cota de ativos, para caber preparação de
        // catálogo antes da publicação.
        if (!$subscription) {
            $cap = 25;
        } elseif ($limit === null) {
            $cap = 100000;
        } else {
            $cap = max(50, ((int) $limit) * 5);
        }

        $count = $this->propertyModel
            ->where('account_id', $accountId)
            ->whereIn('status', ['DRAFT', 'PAUSED'])
            ->countAllResults();

        if ($count >= $cap) {
            return [
                'allowed' => false,
                'message' => "Você atingiu o limite de {$cap} imóveis não publicados (rascunho/pausado). "
                    . 'Publique ou remova alguns antes de importar mais.',
            ];
        }

        return ['allowed' => true];
    }

    public function checkPlanLimits(int $accountId, ?int $currentPropertyId = null, bool $isStaff = false): array
    {
        // 0. BYPASS FOR STAFF
        if ($isStaff) {
            return ['allowed' => true, 'message' => 'Bypass Admin'];
        }

        // 1. Busca ASSINATURA MAIS RECENTE (Independente do status)
        $subscription = $this->subscriptionModel
            ->where('account_id', $accountId)
            ->orderBy('id', 'DESC')
            ->first();

        // SEM ASSINATURA
        if (!$subscription) {
            return ['allowed' => false, 'message' => 'Nenhuma assinatura encontrada. Assine um plano para ativar imóveis.'];
        }

        // STATUS CHECK
        // Ex: CANCELLED
        if ($subscription->status === 'CANCELLED') {
             return ['allowed' => false, 'message' => 'Sua assinatura foi cancelada. Renove seu plano para continuar.'];
        }
        // Ex: INACTIVE ou SUSPENDED (prevenção)
        if (in_array($subscription->status, ['OVERDUE', 'SUSPENDED'])) {
             return ['allowed' => false, 'message' => 'Sua assinatura está suspensa/atrasada. Verifique seu pagamento no menu Assinatura.'];
        }
        
        // Se status não for ACTIVE/TRIAL, bloqueia (catch-all)
        if (!in_array($subscription->status, ['ACTIVE', 'TRIAL'])) {
             return ['allowed' => false, 'message' => 'Sua assinatura não está ativa. Status atual: ' . $subscription->status];
        }

        // DATA CHECK (Expiração)
        if ($subscription->data_fim && strtotime($subscription->data_fim) < time()) {
             return ['allowed' => false, 'message' => 'Sua assinatura expirou em ' . date('d/m/Y', strtotime($subscription->data_fim)) . '. Renove agora.'];
        }

        // DATA CHECK (Futura)
        if ($subscription->data_inicio && strtotime($subscription->data_inicio) > time()) {
             return ['allowed' => false, 'message' => 'Sua assinatura está agendada para iniciar em ' . date('d/m/Y', strtotime($subscription->data_inicio))];
        }

        // 2. Busca Plano
        $plan = $this->planModel->find($subscription->plan_id);
        if (!$plan) {
            return ['allowed' => false, 'message' => 'Erro interno: Plano não encontrado.'];
        }

        // 3. LIMIT CHECK
        // Se ilimitado
        if ($plan->limite_imoveis_ativos === null) {
            return ['allowed' => true, 'message' => 'OK'];
        }

        // Conta quantos imóveis ativos a conta já possui
        $builder = $this->propertyModel->where('account_id', $accountId)->where('status', 'ACTIVE');
        if ($currentPropertyId) {
            $builder->where('id !=', $currentPropertyId);
        }
        
        $count = $builder->countAllResults();

        // --- NOTIFICAÇÃO DE LIMITE PRÓXIMO (90%, 95%, 100%) ---
        if ($count > 0 && $plan->limite_imoveis_ativos > 0) {
            $percentage = round(($count / $plan->limite_imoveis_ativos) * 100);
            
            // Notifica apenas em pontos chave para evitar spam
            if (in_array($percentage, [90, 95, 100])) {
                try {
                    $notificationService = new \App\Services\NotificationService();
                    $accountModel = model('App\Models\AccountModel');
                    $userModel = model('CodeIgniter\Shield\Models\UserModel');
                    
                    $account = $accountModel->find($accountId);
                    $users = $userModel->where('account_id', $accountId)->findAll();
                    
                    if ($account && !empty($users)) {
                        foreach ($users as $user) {
                            $notificationService->notifyPropertyLimitApproaching(
                                $user,
                                $account,
                                $count,
                                $plan->limite_imoveis_ativos,
                                $percentage
                            );
                        }
                    }
                } catch (\Exception $e) {
                    log_message('error', 'Erro ao enviar alerta de limite: ' . $e->getMessage());
                }
            }
        }
        // -------------------------------------

        if ($count >= $plan->limite_imoveis_ativos) {
             return ['allowed' => false, 'message' => "Você atingiu o limite de {$plan->limite_imoveis_ativos} imóveis ativos do seu plano ({$plan->nome}). Faça um upgrade para anunciar mais."];
        }

        return ['allowed' => true, 'message' => 'OK'];
    }

    /**
     * Retorna imóveis em destaque (Recentes + Ativos) com imagem de capa.
     */
    /**
     * Prateleira "Destaques Recomendados" da home — lane B pura (Fase 2), não
     * um ranking de todo mundo com desempate por preço de plano.
     *
     * Elegibilidade idêntica à do slot patrocinado da busca
     * (`HighlightSql::sponsorshipEligible`): curadoria editorial da Habitaweb
     * (`is_destaque`) OU turbo vigente com a feature `exposicao.busca` do
     * plano. Uma imobiliária Prata que compra turbinada avulsa aparece na
     * PRÓPRIA página do imóvel como "Patrocinado", mas não entra nesta
     * prateleira — que é justamente o espaço institucional que a proposta
     * reserva para Ouro/Diamante ("maior exposição... imóveis destacados").
     *
     * Sem eligível suficiente, a prateleira mostra menos itens (a view já
     * trata `empty($featuredProperties)` com "Novos imóveis em breve.") — não
     * é preenchida com imóveis orgânicos só para não ficar vazia.
     */
    public function getFeaturedProperties(int $limit = 6): array
    {
        $builder = $this->propertyModel->builder();
        $builder->select('properties.*, accounts.is_verified as account_verified')
                ->where('properties.status', 'ACTIVE');

        $builder->join('accounts', 'accounts.id = properties.account_id', 'left')
                ->join('subscriptions', "subscriptions.account_id = accounts.id AND subscriptions.status IN ('ACTIVE', 'TRIAL')", 'left')
                ->join('plans', 'plans.id = subscriptions.plan_id', 'left')
                ->where(\App\Libraries\Search\HighlightSql::sponsorshipEligible(), null, false)
                ->groupBy('properties.id')
                ->groupBy('accounts.is_verified')
                ->groupBy('plans.exposure_weight');

        $this->publicVisibility->apply($builder);

        $builder->orderBy(\App\Libraries\Search\HighlightSql::sponsorshipWeight(), 'DESC', false)
                ->orderBy('properties.score_qualidade', 'DESC')
                ->orderBy('properties.created_at', 'DESC');

        $properties = $builder->get($limit)->getResult(\App\Entities\Property::class);

        if (empty($properties)) {
            return [];
        }

        // Carrega a primeira imagem de cada imóvel
        $mediaModel = Factories::models(\App\Models\PropertyMediaModel::class);
        $ids = array_column($properties, 'id');
        
        // Busca todas as mídias desses imóveis
        $medias = $mediaModel->whereIn('property_id', $ids)->findAll();
        
        // Mapa de mídias
        $mediaMap = [];
        foreach ($medias as $media) {
            // Prioriza se for main ou se ainda não tiver setado
            if (!isset($mediaMap[$media->property_id]) || $media->principal) {
                $mediaMap[$media->property_id] = $media->url;
            }
        }

        foreach ($properties as $property) {
            $property->cover_image = $mediaMap[$property->id] ?? null;
        }

        return $properties;
    }

    /**
     * Retorna o POOL de imóveis patrocinados (destaque ou turbo ativos),
     * ordenado por recência — substitui o antigo getSponsoredProperties com
     * ORDER BY RANDOM(): RANDOM() força ordenação completa a cada requisição
     * e é incacheável. O chamador (Home) cacheia este pool e faz o sorteio
     * (shuffle) em PHP a cada requisição, preservando a rotação visual sem
     * custo de query.
     */
    public function getSponsoredPool(int $poolSize = 12): array
    {
        $builder = $this->propertyModel->builder();
        $builder->select('properties.*, accounts.is_verified as account_verified')
                ->join('accounts', 'accounts.id = properties.account_id', 'left')
                ->where('properties.status', 'ACTIVE')
                ->groupStart()
                    ->where('properties.is_destaque', true)
                    ->orWhere(\App\Libraries\Search\HighlightSql::isActive(), null, false)
                ->groupEnd()
                ->groupBy('properties.id')
                ->groupBy('accounts.is_verified')
                ->orderBy('properties.created_at', 'DESC');

        $this->publicVisibility->apply($builder);

        $properties = $builder->get($poolSize)->getResult(\App\Entities\Property::class);

        if (empty($properties)) {
            return [];
        }

        // Carrega imagens
        $mediaModel = Factories::models(\App\Models\PropertyMediaModel::class);
        $ids = array_column($properties, 'id');
        $medias = $mediaModel->whereIn('property_id', $ids)->findAll();
        
        $mediaMap = [];
        foreach ($medias as $media) {
            if (!isset($mediaMap[$media->property_id]) || $media->principal) {
                $mediaMap[$media->property_id] = $media->url;
            }
        }

        foreach ($properties as $property) {
            $property->cover_image = $mediaMap[$property->id] ?? null;
        }

        return $properties;
    }

    /**
     * Imóveis SEM nenhum destaque pago (nem plano nem turbo), ordenados pelo
     * score de qualidade — fallback do mapa da home quando não há nenhum
     * destaque pago disponível (nunca mistura pago com não-pago).
     */
    public function getNonPaidProperties(int $limit = 60): array
    {
        $builder = $this->propertyModel->builder();
        $builder->select('properties.*, accounts.is_verified as account_verified')
                ->join('accounts', 'accounts.id = properties.account_id', 'left')
                ->where('properties.status', 'ACTIVE')
                ->where('properties.is_destaque', false)
                ->where(\App\Libraries\Search\HighlightSql::effectiveLevel() . ' = 0', null, false)
                ->groupBy('properties.id')
                ->groupBy('accounts.is_verified')
                ->orderBy('properties.score_qualidade', 'DESC')
                ->orderBy('properties.created_at', 'DESC');

        $this->publicVisibility->apply($builder);

        $properties = $builder->get($limit)->getResult(\App\Entities\Property::class);

        if (empty($properties)) {
            return [];
        }

        $mediaModel = Factories::models(\App\Models\PropertyMediaModel::class);
        $ids = array_column($properties, 'id');
        $medias = $mediaModel->whereIn('property_id', $ids)->findAll();

        $mediaMap = [];
        foreach ($medias as $media) {
            if (!isset($mediaMap[$media->property_id]) || $media->principal) {
                $mediaMap[$media->property_id] = $media->url;
            }
        }

        foreach ($properties as $property) {
            $property->cover_image = $mediaMap[$property->id] ?? null;
        }

        return $properties;
    }

    /**
     * Lista imóveis com filtros.
     */
    public function listProperties(array $filters = [], int $perPage = 15): array
    {
        $publicOnly = ($filters['_public_only'] ?? false) === true;
        unset($filters['_public_only']);

        $builder = $this->propertyModel->select('properties.*, accounts.tipo_conta as account_type, accounts.nome as account_name, accounts.logo as account_logo, accounts.is_verified as account_verified')
                                       ->select('(SELECT url FROM property_media WHERE property_media.property_id = properties.id AND property_media.deleted_at IS NULL ORDER BY principal DESC, ordem ASC LIMIT 1) as cover_image')
                                       ->join('accounts', 'accounts.id = properties.account_id', 'left')
                                       ->groupBy('properties.id')
                                       ->groupBy('accounts.tipo_conta')
                                       ->groupBy('accounts.nome')
                                       ->groupBy('accounts.logo')
                                       ->groupBy('accounts.is_verified');

        if ($publicOnly) {
            $this->publicVisibility->apply($builder);
        } else {
            // Padrão interno: ACTIVE, a menos que especificado (ou ALL para admin).
            if (isset($filters['show_deleted']) && $filters['show_deleted'] === true) {
                $builder->onlyDeleted();
            } elseif (isset($filters['status']) && $filters['status'] !== 'ALL') {
                $builder->where('properties.status', $filters['status']);
            } elseif (!isset($filters['status'])) {
                $builder->where('properties.status', 'ACTIVE');
            }
        }

        if (isset($filters['account_id'])) {
            $builder->where('properties.account_id', $filters['account_id']);
        }

        if (isset($filters['user_id_responsavel'])) {
            $builder->where('properties.user_id_responsavel', $filters['user_id_responsavel']);
        }

        // Busca pelo identificador do imóvel no sistema do parceiro. É como ele
        // localiza um registro sincronizado sem precisar guardar o nosso id.
        if (!empty($filters['external_id'])) {
            $builder->where('properties.external_id', $filters['external_id']);
        }

        // Novo: Filtro por Tipo de Conta (PF, IMOBILIARIA, CORRETOR)
        if (!empty($filters['account_type'])) {
            $builder->where('accounts.tipo_conta', $filters['account_type']);
        }

        if (isset($filters['promoted_only']) && $filters['promoted_only'] === true) {
             $builder->groupStart()
                     ->where('properties.is_destaque', true)
                     ->orWhere(\App\Libraries\Search\HighlightSql::isActive(), null, false)
                     ->groupEnd();
        }

        $this->applySearchFilters($builder, $filters);

        // Ranking puramente orgânico: só qualidade do anúncio, nunca quanto o
        // anunciante paga. A fórmula antiga usava plans.preco_mensal como peso
        // — Diamante (R$2.490) media 2,5x o de Prata (R$990) em TODA busca,
        // exatamente o que a proposta comercial exige não fazer. Exposição
        // paga agora é posição (slot), não multiplicador de relevância — ver
        // App\Services\Search\SponsoredPlacementService.
        //
        // Efeito colateral: os joins subscriptions/plans e o groupBy que a
        // fórmula antiga exigia (obrigatório no Postgres com GROUP BY) saem
        // desta consulta. Só a lane patrocinada (getSponsoredCandidates, LIMIT
        // pequeno) ainda precisa deles.
        $builder->orderBy('properties.score_qualidade', 'DESC')
                ->orderBy('properties.created_at', 'DESC');

        $results = $builder->paginate($perPage);
        $pager   = $this->propertyModel->pager;

        return [
            'properties' => $results,
            'pager'      => $pager,
        ];
    }

    /** Lista pública; painel e API autenticada continuam usando listProperties(). */
    public function listPublicProperties(array $filters = [], int $perPage = 15): array
    {
        $filters['_public_only'] = true;
        return $this->listProperties($filters, $perPage);
    }

    /**
     * Retorna pins leves para o mapa público, sem HTML e sem carregar galerias.
     */
    public function searchMapPins(array $filters = [], int $limit = 900): array
    {
        $cacheKey = 'public_map_pins_' . md5(json_encode($this->normalizeMapFilters($filters)) . '|' . $limit);
        $cached = cache()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $builder = $this->buildPublicMapSearchQuery($filters, false);
        $builder->select('properties.id, properties.latitude, properties.longitude, properties.preco, properties.tipo_negocio, properties.is_destaque, properties.highlight_level');

        $pins = $builder->limit($limit)->get()->getResult();

        cache()->save($cacheKey, $pins, 30);

        return $pins;
    }

    /**
     * Retorna a lista paginada do mapa com apenas a capa principal e contagem de fotos.
     */
    public function searchMapList(array $filters = [], int $perPage = 18, int $page = 1): array
    {
        $builder = $this->buildPublicMapSearchQuery($filters, true);
        $properties = $builder->paginate($perPage, 'default', $page);
        $pager = $this->propertyModel->pager;

        $total = $pager->getTotal();
        $currentPage = max(1, $page);

        // Slots patrocinados: só na página 1 e só quando o visitante não
        // escolheu uma ordenação explícita (price_asc/price_desc/recent) —
        // quem pede "mais barato primeiro" está dizendo que não quer
        // patrocinado furando a fila. O total/pager continua refletindo só a
        // lane orgânica: o slot reordena o que já ia aparecer, não infla
        // "quantos imóveis correspondem à busca".
        $sort = $filters['sort'] ?? 'relevance';
        if ($currentPage === 1 && $sort === 'relevance') {
            $candidatos = $this->getSponsoredCandidates($filters, \App\Services\Search\SponsoredPlacementService::SLOT_COUNT);
            $properties = (new \App\Services\Search\SponsoredPlacementService())->merge($properties, $candidatos, $currentPage);
        }

        return [
            'properties' => $properties,
            'pager'      => $pager,
            'total'      => $total,
            'page'       => $currentPage,
            'per_page'   => $perPage,
            'has_more'   => ($currentPage * $perPage) < $total,
            'next_page'  => ($currentPage * $perPage) < $total ? $currentPage + 1 : null,
        ];
    }

    /**
     * Query base da busca pública (mapa e lista) — SEM os joins de plano.
     *
     * A fórmula antiga de ordenação usava `plans.preco_mensal`, o que obrigava
     * TODA busca a fazer LEFT JOIN em `subscriptions`+`plans` e GROUP BY
     * (exigência do Postgres para o ORDER BY agregado). O ranking orgânico não
     * usa mais preço de plano nenhum — só a lane patrocinada
     * (`getSponsoredCandidates`, `LIMIT` pequeno) ainda precisa desses dados,
     * então só ela paga o custo do join.
     */
    private function buildPublicMapSearchQuery(array $filters = [], bool $withCover = false)
    {
        $builder = $this->propertyModel
            ->join('accounts', 'accounts.id = properties.account_id', 'left')
            ->groupBy('properties.id')
            ->groupBy('accounts.tipo_conta')
            ->groupBy('accounts.nome')
            ->groupBy('accounts.logo')
            ->groupBy('accounts.is_verified');

        if ($withCover) {
            $builder
                ->select('properties.*, accounts.tipo_conta as account_type, accounts.nome as account_name, accounts.logo as account_logo, accounts.is_verified as account_verified')
                ->select('(SELECT url FROM property_media WHERE property_media.property_id = properties.id AND property_media.deleted_at IS NULL ORDER BY principal DESC, ordem ASC LIMIT 1) as cover_image')
                ->select('(SELECT COUNT(*) FROM property_media WHERE property_media.property_id = properties.id AND property_media.deleted_at IS NULL) as media_count');
        }

        $this->publicVisibility->apply($builder);

        $this->applySearchFilters($builder, $filters);

        // Ranking orgânico puro — ver o comentário equivalente em
        // listProperties(). price_asc/price_desc/recent são escolha explícita
        // do usuário e continuam sem tocar em destaque nenhum.
        $sort = $filters['sort'] ?? 'relevance';
        if ($sort === 'price_asc') {
            $builder->orderBy('properties.preco', 'ASC');
        } elseif ($sort === 'price_desc') {
            $builder->orderBy('properties.preco', 'DESC');
        } elseif ($sort === 'recent') {
            $builder->orderBy('properties.created_at', 'DESC');
        } else {
            $builder->orderBy('properties.score_qualidade', 'DESC')
                ->orderBy('properties.created_at', 'DESC');
        }

        return $builder;
    }

    /**
     * Candidatos elegíveis a slot patrocinado para ESTE MESMO filtro de busca
     * — a lane B da Fase 2.
     *
     * Usa `applySearchFilters()`, o MESMO ponto de montagem do `WHERE` da lane
     * orgânica (`buildPublicMapSearchQuery`): é isso que garante que um
     * patrocinado nunca aparece fora do que o visitante pediu. A elegibilidade
     * em si (`HighlightSql::sponsorshipEligible`) é decidida à parte, e é
     * restritiva de propósito — turbo comprado sozinho não basta, precisa da
     * feature `exposicao.busca` do plano (ver o comentário da própria função).
     */
    public function getSponsoredCandidates(array $filters, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $builder = $this->propertyModel
            ->select('properties.*, accounts.tipo_conta as account_type, accounts.nome as account_name, accounts.logo as account_logo, accounts.is_verified as account_verified')
            ->select('(SELECT url FROM property_media WHERE property_media.property_id = properties.id AND property_media.deleted_at IS NULL ORDER BY principal DESC, ordem ASC LIMIT 1) as cover_image')
            ->select('(SELECT COUNT(*) FROM property_media WHERE property_media.property_id = properties.id AND property_media.deleted_at IS NULL) as media_count')
            ->join('accounts', 'accounts.id = properties.account_id', 'left')
            ->join('subscriptions', "subscriptions.account_id = accounts.id AND subscriptions.status IN ('ACTIVE', 'TRIAL')", 'left')
            ->join('plans', 'plans.id = subscriptions.plan_id', 'left')
            ->where(\App\Libraries\Search\HighlightSql::sponsorshipEligible(), null, false)
            ->groupBy('properties.id')
            ->groupBy('accounts.tipo_conta')
            ->groupBy('accounts.nome')
            ->groupBy('accounts.logo')
            ->groupBy('accounts.is_verified')
            ->groupBy('plans.exposure_weight');

        $this->publicVisibility->apply($builder);
        $this->applySearchFilters($builder, $filters);

        $builder->orderBy(\App\Libraries\Search\HighlightSql::sponsorshipWeight(), 'DESC', false)
                ->orderBy('properties.score_qualidade', 'DESC');

        return $builder->get($limit)->getResult(\App\Entities\Property::class);
    }

    private function normalizeMapFilters(array $filters): array
    {
        ksort($filters);
        return array_filter($filters, static function ($value) {
            return !is_null($value) && $value !== '';
        });
    }

    /**
     * Busca detalhes completos de um imóvel (incluindo mídias).
     */
    public function getPropertyDetails(int $id): ?array
    {
        return $this->loadPropertyDetails($id, false);
    }

    /** Busca pública: indisponíveis se comportam como inexistentes. */
    public function getPublicPropertyDetails(int $id): ?array
    {
        return $this->loadPropertyDetails($id, true);
    }

    private function loadPropertyDetails(int $id, bool $publicOnly): ?array
    {
        $builder = (new PropertyModel())
            ->select('properties.*, accounts.nome as account_name, accounts.telefone as account_phone, accounts.whatsapp as account_whatsapp, accounts.email as account_email, accounts.creci as account_creci, accounts.tipo_conta as account_type, accounts.logo as account_logo, accounts.is_verified as account_verified, accounts.whatsapp_hub_config, accounts.whatsapp_messages_config, clients.nome as client_name')
            ->join('accounts', 'accounts.id = properties.account_id', 'left')
            ->join('clients', 'clients.id = properties.client_id', 'left');

        if ($publicOnly) {
            $this->publicVisibility->apply($builder);
        }

        $property = $builder->find($id);

        if (!$property) {
            return null;
        }

        // Carrega mídias
        $mediaModel = Factories::models(\App\Models\PropertyMediaModel::class);
        $medias = $mediaModel->where('property_id', $id)->orderBy('principal', 'DESC')->orderBy('ordem', 'ASC')->findAll();
        
        // Carrega características
        $featureModel = Factories::models(\App\Models\PropertyFeatureModel::class);
        $features = $featureModel->where('property_id', $id)->findAll();

        $property->images = $medias;
        $property->features = $features;

        // Check favorite if logged in
        $isFavorited = false;
        if (auth()->loggedIn()) {
            $favModel = Factories::models(\App\Models\PropertyFavoriteModel::class);
            $isFavorited = $favModel->where('user_id', auth()->id())
                                    ->where('property_id', $id)
                                    ->countAllResults() > 0;
        }

        return [
            'property' => $property,
            'medias'   => $medias,
            'features' => $features,
            'isFavorited' => $isFavorited
        ];
    }

    /**
     * Incrementa o contador de visitas de um imóvel.
     *
     * Bufferizado em Redis (flush por cron: spark metrics:flush) — sem o
     * buffer, cada page view de detalhe fazia um UPDATE na linha do imóvel,
     * serializando visitas concorrentes do mesmo anúncio e gerando dead
     * tuples/churn de índice numa tabela intensamente lida. Redis fora do ar
     * => cai no UPDATE direto (comportamento antigo), nunca perde a visita.
     */
    public function incrementVisit(int $id): void
    {
        if (service('metricsBuffer')->bufferVisit($id)) {
            return;
        }

        $this->propertyModel->where('id', $id)
                            ->set('visitas_count', 'visitas_count + 1', false)
                            ->update();
    }

    /**
     * Registra a visualização na série diária (`property_view_daily` /
     * `property_view_source_daily`, Fase 4) — sinal adicional para o painel
     * por período, com dedup de visitante único por dia. Não mexe em
     * `incrementVisit`/`visitas_count`: os dois caminhos coexistem, chamados
     * juntos por quem exibe o imóvel.
     *
     * Sem fallback síncrono se o Redis estiver fora: a série diária é
     * agregada por natureza (não existe "linha crua" para gravar direto no
     * Postgres sem reintroduzir o problema que o buffer resolve), então uma
     * visualização durante um blecaute de Redis simplesmente não entra nessa
     * série — nunca derruba a renderização da página.
     */
    public function recordView(int $id): void
    {
        $request = service('request');

        $ip = $request->getIPAddress();

        $userAgent = $request instanceof \CodeIgniter\HTTP\IncomingRequest
            ? (string) $request->getUserAgent()
            : '';

        $origem = \App\Libraries\Metrics\ViewOrigin::classify($request->getHeaderLine('Referer'));

        service('metricsBuffer')->bufferPropertyView($id, $origem, $ip, $userAgent);
    }

    /**
     * Deleta (Soft Delete) um imóvel.
     */
    public function deleteProperty(int $id): bool
    {
        return $this->propertyModel->delete($id);
    }

    /**
     * "Apagar" um imóvel espelhado de integração não pode ser um soft-delete
     * de verdade: o vínculo (property_external_refs) sobrevive, então a
     * PRÓXIMA sincronização não teria como saber que o tenant tentou remover
     * aquilo — o item simplesmente reapareceria como se nada tivesse
     * acontecido, sem nenhum aviso. Em vez disso, pausa (o mesmo estado que o
     * sync já usa para "sumiu da origem"), respeitando a mesma regra que o
     * botão de excluir do admin promete ("Imóvel desativado").
     *
     * @return array{success:bool, paused:bool}
     */
    public function deleteOrPauseProperty(int $id): array
    {
        if (model(\App\Models\PropertyExternalRefModel::class)->isManaged($id)) {
            $updated = (bool) $this->propertyModel->update($id, ['status' => 'PAUSED']);

            if ($updated) {
                PublicPropertyVisibilityService::invalidateCaches();
            }

            return ['success' => $updated, 'paused' => true];
        }

        return ['success' => $this->deleteProperty($id), 'paused' => false];
    }

    /**
     * Restaura um imóvel deletado logicamente.
     */
    public function restoreProperty(int $id): bool
    {
        $restored = $this->propertyModel->builder()
            ->where('id', $id)
            ->update(['deleted_at' => null]);

        if ($restored) {
            PublicPropertyVisibilityService::invalidateCaches();
        }

        return $restored;
    }

    /**
     * Retorna a lista de corretores (usuários) vinculados a uma conta.
     */
    public function getBrokers(int $accountId): array
    {
        $userModel = model('App\Models\UserModel');
        return $userModel->where('account_id', $accountId)
                         ->orderBy('nome', 'ASC')
                         ->findAll();
    }

    /**
     * Encerra um anúncio (Vendido/Alugado) e vincula o lead convertido.
     */
    public function markAsClosed(int $propertyId, string $reason, ?int $leadId = null, ?array $additionalData = null): bool
    {
        $property = $this->propertyModel->find($propertyId);
        if (!$property) {
            return false;
        }

        // Atualiza Imóvel
        $property->status = 'SOLD'; // Status genérico de finalizado
        $property->closing_reason = $reason;
        $property->closing_lead_id = $leadId;
        $property->closed_at = date('Y-m-d H:i:s');

        if ($this->propertyModel->save($property)) {
            // Se houver lead vinculado, atualiza status do lead no CRM
            if ($leadId) {
                $leadService = new \App\Services\LeadService();
                $leadService->updateStatus($leadId, \App\Models\LeadModel::STATUS_CONCLUIDO, $additionalData);
            }

            // Dispara Webhook de encerramento
            service('webhookService')->dispatch('property.closed', [
                'property_id' => $propertyId,
                'reason' => $reason,
                'lead_id' => $leadId,
                'closed_at' => $property->closed_at
            ], $property->account_id);

            return true;
        }

        return false;
    }

    /**
     * Verifica se a conta pode marcar um imóvel como destaque baseado no seu plano.
     * Retorna array com status, mensagem e contadores.
     *
     * @param int $accountId
     * @param int|null $currentPropertyId Se for edição, ignora o imóvel atual na contagem
     * @return array
     */
    public function canMarkAsDestaque(int $accountId, ?int $currentPropertyId = null, bool $isStaff = false): array
    {
        if ($isStaff) {
             return ['allowed' => true, 'remaining' => 999, 'message' => 'Staff Bypass'];
        }
        log_message('info', "[canMarkAsDestaque] Início - accountId: {$accountId}, currentPropertyId: " . ($currentPropertyId ?? 'null'));
        
        // 1. Busca ASSINATURA ATIVA
        $subscription = $this->subscriptionModel
            ->where('account_id', $accountId)
            ->whereIn('status', ['ACTIVE', 'TRIAL'])
            ->orderBy('id', 'DESC')
            ->first();

        log_message('info', "[canMarkAsDestaque] Subscription encontrada: " . ($subscription ? "ID {$subscription->id}, Status: {$subscription->status}, Plan ID: {$subscription->plan_id}" : 'NENHUMA'));

        if (!$subscription) {
            return ['allowed' => false, 'message' => 'Nenhuma assinatura ativa encontrada. Assine um plano para usar selos.'];
        }

        // 2. Busca Plano vinculado à assinatura
        $plan = $this->planModel->find($subscription->plan_id);
        
        if (!$plan) {
            return ['allowed' => false, 'message' => 'Erro interno: Plano não encontrado.'];
        }

        // Se o plano não permite nenhum destaque
        if (($plan->limite_turbo_mensal ?? 0) <= 0) {
             return ['allowed' => false, 'message' => 'Seu plano atual não oferece selos de destaque promocionais.'];
        }

        // 3. Contagem de uso (Regra: Ativos que possuem o selo)
        $builder = $this->propertyModel
            ->where('account_id', $accountId)
            ->where('is_destaque', true);
            
        if ($currentPropertyId) {
            $builder->where('id !=', $currentPropertyId);
        }

        $usedCount = $builder->countAllResults();

        if ($usedCount >= $plan->limite_turbo_mensal) {
            return [
                'allowed' => false,
                'message' => "Você atingiu o limite de {$plan->limite_turbo_mensal} selos de destaque do seu plano.",
                'used'    => $usedCount,
                'limit'   => $plan->limite_turbo_mensal
            ];
        }

        $result = [
            'allowed' => true,
            'used'    => $usedCount,
            'limit'   => $plan->limite_turbo_mensal,
            'remaining' => $plan->limite_turbo_mensal - $usedCount,
            'message' => "Você possui " . ($plan->limite_turbo_mensal - $usedCount) . " selos de destaque disponíveis."
        ];
        
        return $result;
    }

    /**
     * Busca um imóvel incluindo deletados.
     */
    public function getPropertyWithDeleted(int $id): ?\App\Entities\Property
    {
        return $this->propertyModel->withDeleted()->find($id);
    }

    /**
     * Busca um imóvel apenas entre os deletados.
     */
    public function getPropertyOnlyDeleted(int $id): ?\App\Entities\Property
    {
        return $this->propertyModel->onlyDeleted()->find($id);
    }

    /**
     * Busca leads vinculados a um imóvel.
     */
    public function getLeadsForClosure(int $propertyId): array
    {
        $leadModel = Factories::models(\App\Models\LeadModel::class);
        return $leadModel->where('property_id', $propertyId)
                         ->orderBy('created_at', 'DESC')
                         ->findAll();
    }

    /**
     * Retorna opções para os filtros de busca (cidades, bairros, tipos).
     */
    public function getSearchFilterOptions(): array
    {
        // Instâncias LIMPAS do model, não $this->propertyModel: este método é
        // chamado por resolveLocationName() no MEIO da montagem do builder de
        // listProperties — reusar o model compartilhado misturaria o estado do
        // builder em andamento com estas queries de distinct, corrompendo ambas.
        $publicDistinct = function (string $field): array {
            $model = (new PropertyModel())->distinct()->select($field);
            $this->publicVisibility->apply($model);
            return $model->orderBy($field, 'ASC')->findAll();
        };

        return [
            'cidades' => $publicDistinct('cidade'),
            'bairros' => $publicDistinct('bairro'),
            'tipos'   => $publicDistinct('tipo_imovel'),
        ];
    }

    /**
     * Filtros de busca de imóvel — o `WHERE` que define "o que corresponde à
     * pesquisa do visitante". Único ponto de montagem, usado por
     * `listProperties` (catálogo do parceiro/admin) e `buildPublicMapSearchQuery`
     * (busca pública do mapa/lista).
     *
     * Isto é pré-requisito da Fase 2 (exposição por slot): a lane patrocinada
     * precisa aplicar EXATAMENTE o mesmo `WHERE` da lane orgânica, senão um
     * slot pode mostrar imóvel fora do filtro que o visitante pediu — que é
     * exatamente o que o cliente disse que não quer ("não colocar casa de
     * R$1,5mi na frente de quem buscou apto até R$500 mil"). Se cada lane
     * montasse o filtro por um caminho próprio, mais cedo ou tarde os dois
     * caminhos divergiam.
     *
     * Não inclui filtros de ESCOPO (account_id, status, user_id_responsavel,
     * external_id, account_type, show_deleted) — esses decidem QUEM pode ver
     * o quê (painel do parceiro, admin), não O QUE bate com a pesquisa, e só
     * `listProperties` precisa deles.
     */
    private function applySearchFilters($builder, array $filters): void
    {
        if (!empty($filters['tipo_imovel'])) {
            $builder->where('properties.tipo_imovel', $filters['tipo_imovel']);
        }

        if (!empty($filters['tipo_negocio'])) {
            $builder->where('properties.tipo_negocio', $filters['tipo_negocio']);
        }

        if (!empty($filters['quartos'])) {
            $builder->where('properties.quartos >=', (int) $filters['quartos']);
        }

        if (!empty($filters['banheiros'])) {
            $builder->where('properties.banheiros >=', (int) $filters['banheiros']);
        }

        if (!empty($filters['vagas'])) {
            $builder->where('properties.vagas >=', (int) $filters['vagas']);
        }

        // Aba "Lançamentos" da página premium da imobiliária (Fase 5).
        if (isset($filters['is_novo']) && $filters['is_novo'] === true) {
            $builder->where('properties.is_novo', true);
        }

        if (!empty($filters['property_ids'])) {
            $ids = is_array($filters['property_ids']) ? $filters['property_ids'] : explode(',', $filters['property_ids']);
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!empty($ids)) {
                $builder->whereIn('properties.id', $ids);
            }
        }

        // Cidade/bairro: match EXATO indexável em vez do LIKE '%..%' anterior —
        // que além de forçar seq scan era sensível a caso/acento (slug de URL
        // SEO nunca casava com "São Paulo"). resolveLocationName normaliza
        // slug/sem-acento para o nome exato do banco; sem resolução, cai no
        // LOWER() = (coberto pelo índice funcional idx_properties_status_lower_city_neighborhood).
        $this->applyLocationFilter($builder, $filters, 'cidade');
        $this->applyLocationFilter($builder, $filters, 'bairro');

        // isset()+string-vazia, não só isset(): min_price='' chegando aqui
        // (querystring com o campo presente e vazio) faria
        // `WHERE preco >= ''`, que o Postgres rejeita — coluna numérica não
        // aceita string vazia, nem por coerção implícita.
        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $builder->where('properties.preco >=', (float) $filters['min_price']);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $builder->where('properties.preco <=', (float) $filters['max_price']);
        }

        // --- Spatial Filters ---
        if (!empty($filters['bounds'])) {
            // bounds format: "southWestLng,southWestLat,northEastLng,northEastLat"
            $coords = explode(',', $filters['bounds']);
            if (count($coords) === 4) {
                $swLng = (float) $coords[0];
                $swLat = (float) $coords[1];
                $neLng = (float) $coords[2];
                $neLat = (float) $coords[3];

                // Min/max para garantir a ordem correta independente do
                // hemisfério e da direção do arrasto.
                $builder->where('properties.longitude >=', min($swLng, $neLng))
                        ->where('properties.longitude <=', max($swLng, $neLng))
                        ->where('properties.latitude >=', min($swLat, $neLat))
                        ->where('properties.latitude <=', max($swLat, $neLat));
            }
        }

        if (!empty($filters['polygon'])) {
            // polygon format: JSON string de [[lng, lat], [lng, lat], ...]
            $polyData = json_decode($filters['polygon'], true);
            if (is_array($polyData)) {
                $points = [];
                foreach ($polyData as $pt) {
                    if (is_array($pt) && count($pt) >= 2) {
                        $points[] = sprintf('(%F,%F)', (float) $pt[0], (float) $pt[1]);
                    }
                }
                if (count($points) >= 3) {
                    $polyString = '(' . implode(',', $points) . ')';
                    $builder->where("point(properties.longitude, properties.latitude) <@ polygon '{$polyString}'", null, false);
                }
            }
        }
        // -----------------------
    }

    /**
     * Aplica o filtro de cidade/bairro num builder: resolve para o nome exato
     * (usa o índice composto existente) ou, sem resolução, compara por
     * LOWER() = (índice funcional). Compartilhado por listProperties e
     * buildPublicMapSearchQuery.
     */
    private function applyLocationFilter($builder, array $filters, string $field): void
    {
        if (empty($filters[$field])) {
            return;
        }

        $input    = (string) $filters[$field];
        $resolved = $this->resolveLocationName($input, $field);

        if ($resolved !== null) {
            $builder->where("properties.{$field}", $resolved);
        } else {
            $builder->where("LOWER(properties.{$field})", mb_strtolower(trim($input)));
        }
    }

    /**
     * Resolve uma entrada de cidade/bairro (slug de URL SEO tipo "sao-paulo",
     * ou texto digitado sem acento) para o nome EXATO como está no banco
     * ("São Paulo"), comparando as formas transliteradas/minúsculas de ambos
     * os lados via mb_url_title (que remove acentos).
     *
     * Isso corrige um bug real: o LIKE anterior era sensível a caso/acento no
     * Postgres, então /imoveis/venda/sao-paulo simplesmente NÃO filtrava nada
     * de "São Paulo". De quebra, o match exato usa o índice composto
     * (status, cidade, bairro) em vez de seq scan com '%...%'.
     *
     * Usa a mesma lista distinct cacheada por 1h do executeSearch
     * (search_filter_options). Retorna null se não encontrar.
     *
     * @param string $field 'cidade' ou 'bairro'
     */
    public function resolveLocationName(string $input, string $field): ?string
    {
        if (! in_array($field, ['cidade', 'bairro'], true)) {
            return null;
        }

        helper('url'); // mb_url_title (translitera acentos) vive no URL helper

        $options = cache('search_filter_options');
        if ($options === null) {
            $options = $this->getSearchFilterOptions();
            cache()->save('search_filter_options', $options, 3600);
        }

        $needle = mb_url_title(mb_strtolower(trim($input)), '-');
        $list   = $field === 'cidade' ? ($options['cidades'] ?? []) : ($options['bairros'] ?? []);

        foreach ($list as $row) {
            $value = $row->{$field} ?? null;
            if ($value !== null && mb_url_title(mb_strtolower($value), '-') === $needle) {
                return $value;
            }
        }

        return null;
    }
    /**
     * Define se o imóvel é um Destaque do Plano.
     */
    public function setPlanHighlight(int $propertyId, bool $status): array
    {
        $property = $this->propertyModel->find($propertyId);
        if (!$property) {
            return ['success' => false, 'message' => 'Imóvel 404'];
        }

        // Se estiver ativando, verifica limite
        if ($status) {
            $check = $this->canMarkAsDestaque($property->account_id);
            if (!$check['allowed']) {
                return ['success' => false, 'message' => $check['message']];
            }
        }

        $this->propertyModel->update($propertyId, ['is_destaque' => $status]);
        
        return ['success' => true, 'message' => $status ? 'Imóvel destacado com sucesso!' : 'Destaque removido com sucesso.'];
    }
    /**
     * Adiciona uma mídia (imagem) ao imóvel.
     * 
     * @param int $propertyId
     * @param \CodeIgniter\HTTP\Files\UploadedFile $file
     * @return array
     */
    public function addMedia(int $propertyId, $file): array
    {
        // 1. Validate Property
        $property = $this->propertyModel->find($propertyId);
        if (!$property) {
            return ['success' => false, 'message' => 'Imóvel não encontrado.'];
        }

        // 1b. Limite de fotos do plano.
        // plans.limite_fotos_por_imovel existia, era exibido em três telas e
        // nunca foi lido por nenhum caminho de upload — qualquer conta podia
        // subir fotos ilimitadas independentemente do plano contratado.
        $photoLimit = $this->checkPhotoLimit((int) $property->account_id, $propertyId);
        if (!$photoLimit['allowed']) {
            return ['success' => false, 'message' => $photoLimit['message'], 'code' => 'PHOTO_LIMIT_REACHED'];
        }

        // 2. Validate File
        if (!$file->isValid() || $file->hasMoved()) {
            return ['success' => false, 'message' => 'Arquivo inválido ou já movido.'];
        }

        // 2b. Validação REAL de conteúdo — impede upload de scripts disfarçados de imagem.
        //     (Espelha as checagens já usadas no upload do painel admin.)
        $maxBytes = 5 * 1024 * 1024; // 5 MB
        if ($file->getSize() > $maxBytes) {
            return ['success' => false, 'message' => 'Arquivo excede o tamanho máximo de 5 MB.'];
        }

        // MIME real lido do conteúdo do arquivo, nunca do header enviado pelo cliente.
        $realMime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $realMime = finfo_file($finfo, $file->getTempName());
            finfo_close($finfo);
        } else {
            $realMime = $file->getMimeType();
        }

        // Mapa de MIME permitido -> extensão segura forçada no nome do arquivo salvo.
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($mimeToExt[$realMime])) {
            return ['success' => false, 'message' => 'Tipo de arquivo não permitido. Envie apenas imagens JPG, PNG ou WebP.'];
        }

        // Confirma que é uma imagem realmente decodificável, com dimensões sãs.
        $dimensions = @getimagesize($file->getTempName());
        if ($dimensions === false) {
            return ['success' => false, 'message' => 'O arquivo enviado não é uma imagem válida.'];
        }
        [$imgWidth, $imgHeight] = $dimensions;
        if ($imgWidth < 100 || $imgHeight < 100 || $imgWidth > 12000 || $imgHeight > 12000) {
            return ['success' => false, 'message' => 'Dimensões de imagem inválidas (entre 100px e 12000px).'];
        }

        // 3. Store File
        try {
            // Nome aleatório com extensão FORÇADA pelo MIME real (nunca a extensão do cliente,
            // que poderia ser .php). Elimina o vetor de RCE mesmo para arquivos polyglot.
            $newName = bin2hex(random_bytes(16)) . '.' . $mimeToExt[$realMime];
            $targetPath = 'uploads/properties/' . $propertyId . '/' . $newName;

            // Remove EXIF (GPS, câmera, timestamp) ANTES de gerar variantes e
            // publicar. Esta limpeza só existia no upload do painel admin; pela
            // API as fotos do parceiro iam para o ar com a localização embutida.
            \App\Libraries\Media\ImageSanitizer::stripMetadata($file->getTempName());

            return $this->persistMedia($propertyId, $file->getTempName(), $targetPath);

        } catch (\Exception $e) {
            log_message('error', 'Erro ao fazer upload de mídia: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erro interno ao salvar arquivo.'];
        }
    }

    /**
     * Adiciona uma imagem ao imóvel a partir de uma URL pública.
     *
     * É o caminho que torna a sincronização de catálogo viável: o parceiro
     * manda as URLs que já usa no site dele, em vez de N uploads multipart.
     * O download é feito pelo RemoteImageFetcher, que aplica as defesas de SSRF.
     *
     * @param array{ordem?: int, principal?: bool} $options
     * @return array{success: bool, media?: array, skipped?: bool, message?: string}
     */
    public function addMediaFromUrl(string $url, int $propertyId, array $options = []): array
    {
        $property = $this->propertyModel->find($propertyId);
        if (!$property) {
            return ['success' => false, 'message' => 'Imóvel não encontrado.'];
        }

        $mediaModel = Factories::models(\App\Models\PropertyMediaModel::class);
        $urlHash    = hash('sha256', trim($url));

        // Dedupe: reimportar o mesmo catálogo não deve rebaixar as mesmas fotos.
        // withDeleted() de propósito: PropertyMediaModel usa soft delete, e sem
        // isto uma foto que o TENANT apagou manualmente ficava invisível pra
        // esta checagem — o próximo sync não encontrava o hash, achava que a
        // foto era nova, e a recriava. A exclusão do tenant "não pegava" até a
        // próxima mudança de conteúdo do imóvel.
        $existing = $mediaModel->withDeleted()
                               ->where('property_id', $propertyId)
                               ->where('source_url_hash', $urlHash)
                               ->first();

        if ($existing) {
            return [
                'success' => true,
                'skipped' => true,
                'media'   => [
                    'id'        => (int) $existing->id,
                    'url'       => media_url($existing->url),
                    'principal' => (bool) $existing->principal,
                ],
            ];
        }

        $photoLimit = $this->checkPhotoLimit((int) $property->account_id, $propertyId);
        if (!$photoLimit['allowed']) {
            return ['success' => false, 'message' => $photoLimit['message'], 'code' => 'PHOTO_LIMIT_REACHED'];
        }

        $fetched = $this->imageFetcher()->fetch($url);

        if (!$fetched['success']) {
            return ['success' => false, 'message' => $fetched['message']];
        }

        try {
            \App\Libraries\Media\ImageSanitizer::stripMetadata($fetched['path']);

            $newName    = bin2hex(random_bytes(16)) . '.' . $fetched['extension'];
            $targetPath = 'uploads/properties/' . $propertyId . '/' . $newName;

            return $this->persistMedia($propertyId, $fetched['path'], $targetPath, [
                'source_url' => trim($url),
                'ordem'      => $options['ordem'] ?? null,
                'principal'  => $options['principal'] ?? null,
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[PropertyService] falha ao ingerir imagem por URL: ' . $e->getMessage());

            return ['success' => false, 'message' => 'Erro interno ao salvar a imagem baixada.'];
        } finally {
            // persistMedia consome o arquivo via put(); se algo falhou antes
            // disso, o temporário ainda existe e precisa sair.
            if (is_file($fetched['path'])) {
                @unlink($fetched['path']);
            }
        }
    }

    /**
     * Etapa final comum aos dois caminhos de ingestão (upload e URL):
     * gera variantes, publica no storage e grava a linha em property_media.
     *
     * @param array{source_url?: string, ordem?: int|null, principal?: bool|null} $meta
     */
    private function persistMedia(int $propertyId, string $sourcePath, string $targetPath, array $meta = []): array
    {
        // Variantes (thumbnails card/gallery) ANTES do put() do original —
        // LocalStorage::put() consome (unlink) o arquivo de origem. Falha
        // de variante não derruba o upload (o helper cai no original).
        (new \App\Libraries\Media\ImageVariantGenerator())->generate($sourcePath, $targetPath);

        // Via storage abstrato (disco público): permite trocar disco local
        // por S3/NFS sem tocar aqui — validação de conteúdo permanece no
        // service, ANTES do put(), independente do backend.
        $storage   = service('publicStorage');
        $publicUrl = $storage->put($targetPath, $sourcePath);

        $mediaModel = Factories::models(\App\Models\PropertyMediaModel::class);

        $count  = $mediaModel->countByProperty($propertyId);
        $isMain = $meta['principal'] ?? ($count === 0);

        $sourceUrl = $meta['source_url'] ?? null;

        $mediaId = $mediaModel->insert([
            'property_id'     => $propertyId,
            // 'IMAGE' em vez de 'imagem': os três caminhos gravavam valores
            // diferentes ('FOTO', 'IMAGE', 'imagem') para a mesma coisa.
            'tipo'            => 'IMAGE',
            'url'             => $publicUrl,
            'ordem'           => $meta['ordem'] ?? ($count + 1),
            'principal'       => $isMain,
            'source_url'      => $sourceUrl,
            'source_url_hash' => $sourceUrl ? hash('sha256', $sourceUrl) : null,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        // Garante exatamente uma capa. Sem isso, marcar principal=true num item
        // do import deixaria duas fotos como capa ao mesmo tempo.
        if ($isMain) {
            $mediaModel->setMainMedia($propertyId, (int) $mediaId);
        } else {
            $mediaModel->sanitizeMain($propertyId);
        }

        service('rankingService')->updateScore($propertyId);

        return [
            'success' => true,
            'skipped' => false,
            'media'   => [
                'id'        => (int) $mediaId,
                'url'       => $storage->getPublicUrl($publicUrl),
                'path'      => $publicUrl,
                'principal' => (bool) $isMain,
            ],
        ];
    }

    /**
     * Verifica o limite de fotos por imóvel do plano da conta.
     *
     * @return array{allowed: bool, message?: string, limit?: int|null, used?: int}
     */
    public function checkPhotoLimit(int $accountId, int $propertyId): array
    {
        $subscription = $this->subscriptionModel
            ->where('account_id', $accountId)
            ->whereIn('status', ['ACTIVE', 'TRIAL'])
            ->orderBy('id', 'DESC')
            ->first();

        if (!$subscription) {
            return ['allowed' => true]; // sem assinatura, quem barra é o AdminAuth/plan limit
        }

        $plan = $this->planModel->find($subscription->plan_id);

        // NULL/0 = sem limite configurado.
        if (!$plan || empty($plan->limite_fotos_por_imovel)) {
            return ['allowed' => true];
        }

        $limit = (int) $plan->limite_fotos_por_imovel;
        $used  = Factories::models(\App\Models\PropertyMediaModel::class)->countByProperty($propertyId);

        if ($used >= $limit) {
            return [
                'allowed' => false,
                'limit'   => $limit,
                'used'    => $used,
                'message' => "Este imóvel já atingiu o limite de {$limit} foto(s) do seu plano ({$plan->nome}). Faça upgrade para adicionar mais.",
            ];
        }

        return ['allowed' => true, 'limit' => $limit, 'used' => $used];
    }

    /**
     * Remove uma mídia do imóvel.
     * 
     * @param int $propertyId
     * @param int $mediaId
     * @return array
     */
    public function deleteMedia(int $propertyId, int $mediaId): array
    {
        $mediaModel = Factories::models(\App\Models\PropertyMediaModel::class);
        $media = $mediaModel->find($mediaId);

        if (!$media || $media->property_id != $propertyId) {
            return ['success' => false, 'message' => 'Mídia não encontrada ou não pertence a este imóvel.'];
        }

        if ($mediaModel->delete($mediaId)) {
            // Remove o arquivo físico do storage. O soft-delete no banco não torna o
            // arquivo inacessível por URL, então ele precisa ser apagado explicitamente.
            if (!empty($media->url)) {
                service('publicStorage')->delete($media->url);
                // Variantes (card/gallery) acompanham o original.
                (new \App\Libraries\Media\ImageVariantGenerator())->deleteVariants($media->url);
            }

            // Se era a principal, define uma nova principal
            if ($media->principal) {
                // Pega a próxima mais antiga
                $next = $mediaModel->where('property_id', $propertyId)
                                  ->orderBy('id', 'ASC')
                                  ->first();
                if ($next) {
                    $mediaModel->update($next->id, ['principal' => true]);
                }
            }

            // Update Score
            service('rankingService')->updateScore($propertyId);

            return ['success' => true, 'message' => 'Mídia removida com sucesso.'];
        }

        return ['success' => false, 'message' => 'Erro ao remover mídia.'];
    }

    /**
     * Define uma mídia como principal (Capa).
     */
    public function setMainMedia(int $propertyId, int $mediaId): array
    {
        $mediaModel = Factories::models(\App\Models\PropertyMediaModel::class);
        $media = $mediaModel->find($mediaId);

        if (!$media || $media->property_id != $propertyId) {
            return ['success' => false, 'message' => 'Mídia não encontrada.'];
        }

        if ($mediaModel->setMainMedia($propertyId, $mediaId)) {
            return ['success' => true, 'message' => 'Imagem de capa atualizada.'];
        }

        return ['success' => false, 'message' => 'Erro ao atualizar capa.'];
    }

    /**
     * Conta imóveis ativos de uma conta.
     * 
     * @param int $accountId
     * @return int
     */
    public function countActivePropertiesByAccount(int $accountId): int
    {
        return $this->propertyModel->where([
            'account_id' => $accountId,
            'status'     => 'ACTIVE'
        ])->countAllResults();
    }

    public function countPublicPropertiesByAccount(int $accountId): int
    {
        $model = (new PropertyModel())->where('properties.account_id', $accountId);
        $this->publicVisibility->apply($model);
        return $model->countAllResults();
    }

    /**
     * Mesma contagem de countPublicPropertiesByAccount(), mas em lote — uma
     * query GROUP BY em vez de uma por conta. PartnerController::index()
     * chamava a versão singular dentro de um foreach (N+1: uma query extra
     * por parceiro da página); contas ausentes do resultado (zero imóveis
     * públicos) não aparecem na chave, então o chamador deve usar `?? 0`.
     *
     * @param int[] $accountIds
     * @return array<int,int> account_id => contagem
     */
    public function countPublicPropertiesByAccounts(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $model = (new PropertyModel())
            ->select('properties.account_id, COUNT(*) as total')
            ->whereIn('properties.account_id', $accountIds)
            ->groupBy('properties.account_id');
        $this->publicVisibility->apply($model);

        $rows = $model->findAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->account_id] = (int) $row->total;
        }

        return $counts;
    }

    public function countPublicProperties(): int
    {
        $model = new PropertyModel();
        $this->publicVisibility->apply($model);
        return $model->countAllResults();
    }
}
