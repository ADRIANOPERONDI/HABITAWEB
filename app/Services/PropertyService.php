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
    protected PropertyModel $propertyModel;
    protected SubscriptionModel $subscriptionModel;
    protected PlanModel $planModel;
    protected CurationService $curationService;

    public function __construct()
    {
        $this->propertyModel     = Factories::models(PropertyModel::class);
        $this->subscriptionModel = Factories::models(SubscriptionModel::class);
        $this->planModel         = Factories::models(PlanModel::class);
        $this->curationService   = new CurationService();
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
    public function trySaveProperty(array $data, ?int $id = null, bool $isStaff = false): array
    {
        try {
            // 0. Load or New
            $property = $id ? $this->propertyModel->find($id) : new Property();

            if ($id && !$property) {
                log_message('emergency', '[PropertyService] Property not found for ID: ' . $id);
                return [
                    'success' => false,
                    'data'    => null,
                    'errors'  => [],
                    'message' => 'Imóvel não encontrado.',
                ];
            }

            log_message('emergency', '[PropertyService] Processing ID: ' . ($id ?? 'NEW'));

            // 1. Sanitization (PT-BR -> Decimal)
            $numericFields = [
                'preco', 'valor_condominio', 'iptu', 'renda_mensal_estimada', 'area_total', 
                'area_construida', 'latitude', 'longitude', 'client_id', 'user_id_responsavel',
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

            // 2. Map Booleans (Explicitly handle missing checkboxes)
            $booleanFields = [
                'is_destaque', 'is_novo', 'is_exclusivo', 'aceita_pets', 'mobiliado', 
                'semimobiliado', 'is_desocupado', 'is_locado', 'indicado_investidor', 
                'indicado_primeira_moradia', 'indicado_temporada'
            ];
            foreach ($booleanFields as $field) {
                // If not in $data, it was unchecked, so force false.
                $data[$field] = isset($data[$field]) && ($data[$field] === '1' || $data[$field] === true || $data[$field] === 1);
            }

            // 3. FILL ENTITY
            $property->fill($data);
            
            // Debug SEO: Verify if fill put them in attributes
            $rawMeta = $property->toArray(); 
            log_message('emergency', '[PropertyService] Entity attributes after fill - Meta Title: ' . ($property->meta_title ?? 'NULL'));

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
                log_message('emergency', '[PropertyService] SAVE ERROR: ' . json_encode($this->propertyModel->errors()));
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
            log_message('emergency', '[PropertyService] Save success for ID: ' . $savedId);

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
            cache()->delete('filter_cities');
            cache()->delete('filter_neighborhoods');
            cache()->delete('filter_types');

            // RANKING (Async update score)
            $rankingService = service('rankingService');
            $rankingService->updateScore($savedId);

            return [
                'success' => true,
                'data'    => $this->propertyModel->find($savedId),
                'errors'  => [],
                'message' => 'Imóvel salvo com sucesso.',
            ];

        } catch (\Exception $e) {
            if (!empty($planLockActive)) {
                $planDb->transRollback();
            }
            log_message('emergency', '[PropertyService] EXCEPTION: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
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
        log_message('emergency', '[PropertyService] checkPlanLimits - Account: ' . $accountId . ' Count: ' . $count . ' Limit: ' . ($plan->limite_imoveis_ativos ?? 'Unlimited'));

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
    public function getFeaturedProperties(int $limit = 6): array
    {
        // Use Builder to allow Joins
        $builder = $this->propertyModel->builder();
        $builder->select('properties.*, accounts.is_verified as account_verified')
                ->where('properties.status', 'ACTIVE');

        // Joins para buscar dados do Plano + Assinatura (WEIGHTED SORT)
        $builder->join('accounts', 'accounts.id = properties.account_id', 'left')
                ->join('subscriptions', "subscriptions.account_id = accounts.id AND subscriptions.status IN ('ACTIVE', 'TRIAL')", 'left')
                ->join('plans', 'plans.id = subscriptions.plan_id', 'left')
                ->groupBy('properties.id')
                ->groupBy('accounts.is_verified')
                ->groupBy('plans.preco_mensal'); // Required for ORDER BY in Postgres

        // Formula: (PlanPrice + (IsDestaque * 100) + (TurboLevel * 100)) * (Score / 100)
        $sqlSort = "(COALESCE(plans.preco_mensal, 0) + (CASE WHEN properties.is_destaque = true THEN 100 ELSE 0 END) + (COALESCE(properties.highlight_level, 0) * 100)) * (COALESCE(properties.score_qualidade, 0) / 100)";
        
        $builder->orderBy($sqlSort, 'DESC', false)
                ->orderBy('properties.created_at', 'DESC');
                
        $properties = $builder->get($limit)->getResult(\App\Entities\Property::class); // Get results as Entities

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
                    ->orWhere('properties.highlight_level >', 0)
                ->groupEnd()
                ->groupBy('properties.id')
                ->groupBy('accounts.is_verified')
                ->orderBy('properties.created_at', 'DESC');

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
     * Lista imóveis com filtros.
     */
    public function listProperties(array $filters = [], int $perPage = 15): array
    {
        $builder = $this->propertyModel->select('properties.*, accounts.tipo_conta as account_type, accounts.nome as account_name, accounts.logo as account_logo, accounts.is_verified as account_verified')
                                       ->select('(SELECT url FROM property_media WHERE property_media.property_id = properties.id AND property_media.deleted_at IS NULL ORDER BY principal DESC, ordem ASC LIMIT 1) as cover_image')
                                       ->join('accounts', 'accounts.id = properties.account_id', 'left');

        // Joins para buscar dados do Plano + Assinatura
        $builder->join('subscriptions', "subscriptions.account_id = accounts.id AND subscriptions.status IN ('ACTIVE', 'TRIAL')", 'left')
                ->join('plans', 'plans.id = subscriptions.plan_id', 'left')
                ->groupBy('properties.id')
                ->groupBy('accounts.tipo_conta')  
                ->groupBy('accounts.nome')       
                ->groupBy('accounts.logo')       
                ->groupBy('accounts.is_verified')
                ->groupBy('plans.preco_mensal');

        // Esconder propriedades de contas com faturas atrasadas há mais de 3 dias (apenas em produção para evitar que dados sumam localmente)
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            $txModel = model('App\Models\PaymentTransactionModel');
            $blockedAccountIds = $txModel->getOverdueAccountIdsCached(3);

            if (!empty($blockedAccountIds)) {
                $builder->whereNotIn('properties.account_id', $blockedAccountIds);
            }
        }

        // Padrão: ACTIVE, a menos que especificado (ou se for 'ALL' para admin)
        if (isset($filters['show_deleted']) && $filters['show_deleted'] === true) {
            $builder->onlyDeleted();
        } elseif (isset($filters['status']) && $filters['status'] !== 'ALL') {
             $builder->where('properties.status', $filters['status']);
        } elseif (!isset($filters['status'])) {
             $builder->where('properties.status', 'ACTIVE');
        }

        if (isset($filters['account_id'])) {
            $builder->where('properties.account_id', $filters['account_id']);
        }

        if (isset($filters['user_id_responsavel'])) {
            $builder->where('properties.user_id_responsavel', $filters['user_id_responsavel']);
        }
        
        // Novo: Filtro por Tipo de Conta (PF, IMOBILIARIA, CORRETOR)
        if (!empty($filters['account_type'])) {
            $builder->where('accounts.tipo_conta', $filters['account_type']);
        }

        if (!empty($filters['tipo_imovel'])) {
            $builder->where('properties.tipo_imovel', $filters['tipo_imovel']);
        }
        
        if (!empty($filters['tipo_negocio'])) {
            $builder->where('properties.tipo_negocio', $filters['tipo_negocio']);
        }

        if (!empty($filters['quartos'])) {
            $builder->where('properties.quartos >=', $filters['quartos']);
        }

        if (!empty($filters['banheiros'])) {
            $builder->where('properties.banheiros >=', $filters['banheiros']);
        }

        if (!empty($filters['vagas'])) {
            $builder->where('properties.vagas >=', $filters['vagas']);
        }
        
        if (!empty($filters['property_ids'])) {
            $ids = is_array($filters['property_ids']) ? $filters['property_ids'] : explode(',', $filters['property_ids']);
            $ids = array_map('intval', $ids);
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

        if (isset($filters['min_price'])) {
            $builder->where('properties.preco >=', $filters['min_price']);
        }
        
        if (isset($filters['max_price'])) {
            $builder->where('properties.preco <=', $filters['max_price']);
        }
        
        if (isset($filters['promoted_only']) && $filters['promoted_only'] === true) {
             $builder->groupStart()
                     ->where('properties.is_destaque', true)
                     ->orWhere('properties.highlight_level >', 0)
                     ->groupEnd();
        }

        // --- Spatial Filters ---
        if (!empty($filters['bounds'])) {
            // bounds format: "southWestLng,southWestLat,northEastLng,northEastLat"
            $coords = explode(',', $filters['bounds']);
            if (count($coords) === 4) {
                $swLng = (float)$coords[0];
                $swLat = (float)$coords[1];
                $neLng = (float)$coords[2];
                $neLat = (float)$coords[3];
                
                // Calcular mínimo e máximo para garantir a ordem correta independente do hemisfério e direção do arrasto
                $minLng = min($swLng, $neLng);
                $maxLng = max($swLng, $neLng);
                $minLat = min($swLat, $neLat);
                $maxLat = max($swLat, $neLat);
                
                $builder->where('properties.longitude >=', $minLng)
                        ->where('properties.longitude <=', $maxLng)
                        ->where('properties.latitude >=', $minLat)
                        ->where('properties.latitude <=', $maxLat);
            }
        }

        if (!empty($filters['polygon'])) {
            // polygon format: JSON string of array of coordinates [[lng, lat], [lng, lat], ...] or GeoJSON
            $polyData = json_decode($filters['polygon'], true);
            if (is_array($polyData)) {
                $points = [];
                // Handle basic array of [lng, lat]
                foreach ($polyData as $pt) {
                    if (is_array($pt) && count($pt) >= 2) {
                        $points[] = sprintf('(%F,%F)', (float)$pt[0], (float)$pt[1]);
                    }
                }
                if (count($points) >= 3) {
                    $polyString = '(' . implode(',', $points) . ')';
                    $builder->where("point(properties.longitude, properties.latitude) <@ polygon '{$polyString}'", null, false);
                }
            }
        }
        // -----------------------

        // Ordenação Ponderada
        // Formula: (PlanPrice + (TurboLevel * 100)) * (Score / 100)
        // 1. COALESCE(plans.preco_mensal, 0): Valor do plano base (0 se Free)
        // 2. (properties.highlight_level * 100): Turbo adiciona "valor virtual" (Lvl 1 = +100, Lvl 2 = +200)
        // 3. * (properties.score_qualidade / 100): Score age como multiplicador de eficiência (0.0 a 1.0)
        // Alta qualidade aproveita 100% do investimento. Baixa qualidade desperdiça.
        
        $sqlSort = "(COALESCE(plans.preco_mensal, 0) + (COALESCE(properties.highlight_level, 0) * 100)) * (COALESCE(properties.score_qualidade, 0) / 100)";
        
        $builder->orderBy($sqlSort, 'DESC', false)
                ->orderBy('properties.created_at', 'DESC');

        $results = $builder->paginate($perPage);
        $pager   = $this->propertyModel->pager;

        return [
            'properties' => $results,
            'pager'      => $pager,
        ];
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

    private function buildPublicMapSearchQuery(array $filters = [], bool $withCover = false)
    {
        $builder = $this->propertyModel
            ->join('accounts', 'accounts.id = properties.account_id', 'left')
            ->join('subscriptions', "subscriptions.account_id = accounts.id AND subscriptions.status IN ('ACTIVE', 'TRIAL')", 'left')
            ->join('plans', 'plans.id = subscriptions.plan_id', 'left')
            ->groupBy('properties.id')
            ->groupBy('accounts.tipo_conta')
            ->groupBy('accounts.nome')
            ->groupBy('accounts.logo')
            ->groupBy('accounts.is_verified')
            ->groupBy('plans.preco_mensal');

        if ($withCover) {
            $builder
                ->select('properties.*, accounts.tipo_conta as account_type, accounts.nome as account_name, accounts.logo as account_logo, accounts.is_verified as account_verified')
                ->select('(SELECT url FROM property_media WHERE property_media.property_id = properties.id AND property_media.deleted_at IS NULL ORDER BY principal DESC, ordem ASC LIMIT 1) as cover_image')
                ->select('(SELECT COUNT(*) FROM property_media WHERE property_media.property_id = properties.id AND property_media.deleted_at IS NULL) as media_count');
        }

        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            $txModel = model('App\Models\PaymentTransactionModel');
            $blockedAccountIds = $txModel->getOverdueAccountIdsCached(3);

            if (!empty($blockedAccountIds)) {
                $builder->whereNotIn('properties.account_id', $blockedAccountIds);
            }
        }

        $builder->where('properties.status', $filters['status'] ?? 'ACTIVE');

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

        if (!empty($filters['property_ids'])) {
            $ids = is_array($filters['property_ids']) ? $filters['property_ids'] : explode(',', $filters['property_ids']);
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!empty($ids)) {
                $builder->whereIn('properties.id', $ids);
            }
        }

        // Mesmo racional do listProperties: match exato indexável (ver
        // resolveLocationName) em vez de LIKE sensível a caso/acento.
        $this->applyLocationFilter($builder, $filters, 'cidade');
        $this->applyLocationFilter($builder, $filters, 'bairro');

        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $builder->where('properties.preco >=', (float) $filters['min_price']);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $builder->where('properties.preco <=', (float) $filters['max_price']);
        }

        if (!empty($filters['bounds'])) {
            $coords = explode(',', $filters['bounds']);
            if (count($coords) === 4) {
                $swLng = (float) $coords[0];
                $swLat = (float) $coords[1];
                $neLng = (float) $coords[2];
                $neLat = (float) $coords[3];

                $builder->where('properties.longitude >=', min($swLng, $neLng))
                    ->where('properties.longitude <=', max($swLng, $neLng))
                    ->where('properties.latitude >=', min($swLat, $neLat))
                    ->where('properties.latitude <=', max($swLat, $neLat));
            }
        }

        if (!empty($filters['polygon'])) {
            $polyData = json_decode($filters['polygon'], true);
            if (is_array($polyData)) {
                $points = [];
                foreach ($polyData as $pt) {
                    if (is_array($pt) && count($pt) >= 2) {
                        $points[] = sprintf('(%F,%F)', (float) $pt[0], (float) $pt[1]);
                    }
                }
                if (count($points) >= 3) {
                    $builder->where("point(properties.longitude, properties.latitude) <@ polygon '(" . implode(',', $points) . ")'", null, false);
                }
            }
        }

        $sort = $filters['sort'] ?? 'relevance';
        if ($sort === 'price_asc') {
            $builder->orderBy('properties.preco', 'ASC');
        } elseif ($sort === 'price_desc') {
            $builder->orderBy('properties.preco', 'DESC');
        } elseif ($sort === 'recent') {
            $builder->orderBy('properties.created_at', 'DESC');
        } else {
            $sqlSort = "(COALESCE(plans.preco_mensal, 0) + (COALESCE(properties.highlight_level, 0) * 100)) * (COALESCE(properties.score_qualidade, 0) / 100)";
            $builder->orderBy($sqlSort, 'DESC', false)
                ->orderBy('properties.created_at', 'DESC');
        }

        return $builder;
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
        $property = $this->propertyModel->select('properties.*, accounts.nome as account_name, accounts.telefone as account_phone, accounts.whatsapp as account_whatsapp, accounts.email as account_email, accounts.creci as account_creci, accounts.tipo_conta as account_type, accounts.logo as account_logo, accounts.is_verified as account_verified, accounts.whatsapp_hub_config, accounts.whatsapp_messages_config, clients.nome as client_name')
                                   ->join('accounts', 'accounts.id = properties.account_id', 'left')
                                   ->join('clients', 'clients.id = properties.client_id', 'left')
                                   ->find($id);

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
     * Deleta (Soft Delete) um imóvel.
     */
    public function deleteProperty(int $id): bool
    {
        return $this->propertyModel->delete($id);
    }

    /**
     * Restaura um imóvel deletado logicamente.
     */
    public function restoreProperty(int $id): bool
    {
        return $this->propertyModel->builder()
                                   ->where('id', $id)
                                   ->update(['deleted_at' => null]);
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
        log_message('emergency', "[canMarkAsDestaque] Plan encontrado: " . ($plan ? "ID {$plan->id}, Nome: {$plan->nome}, Limite Turbo: " . ($plan->limite_turbo_mensal ?? 'null') : 'NENHUM'));
        
        if (!$plan) {
            return ['allowed' => false, 'message' => 'Erro interno: Plano não encontrado.'];
        }

        // Se o plano não permite nenhum destaque
        if (($plan->limite_turbo_mensal ?? 0) <= 0) {
             log_message('emergency', "[canMarkAsDestaque] Plano NÃO permite destaque. Limite: " . ($plan->limite_turbo_mensal ?? 0));
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
        
        log_message('emergency', "[canMarkAsDestaque] Destaques usados: {$usedCount} / {$plan->limite_turbo_mensal}");

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
        
        log_message('emergency', "[canMarkAsDestaque] RESULTADO FINAL: allowed=true, remaining=" . ($plan->limite_turbo_mensal - $usedCount));
        
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
        return [
            'cidades' => (new PropertyModel())->distinct()->select('cidade')->where('status', 'ACTIVE')->orderBy('cidade', 'ASC')->findAll(),
            'bairros' => (new PropertyModel())->distinct()->select('bairro')->where('status', 'ACTIVE')->orderBy('bairro', 'ASC')->findAll(),
            'tipos'   => (new PropertyModel())->distinct()->select('tipo_imovel')->where('status', 'ACTIVE')->orderBy('tipo_imovel', 'ASC')->findAll(),
        ];
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

            // Variantes (thumbnails card/gallery) ANTES do put() do original —
            // LocalStorage::put() consome (unlink) o arquivo de origem. Falha
            // de variante não derruba o upload (o helper cai no original).
            (new \App\Libraries\Media\ImageVariantGenerator())->generate($file->getTempName(), $targetPath);

            // Via storage abstrato (disco público): permite trocar disco local
            // por S3/NFS sem tocar aqui — validação de conteúdo acima permanece
            // no service, ANTES do put(), independente do backend.
            $storage = service('publicStorage');
            $publicUrl = $storage->put($targetPath, $file->getTempName());

            // 4. Insert into DB
            $mediaModel = Factories::models(\App\Models\PropertyMediaModel::class);
            
            // Check if it's the first image (to set as Main)
            $count = $mediaModel->countByProperty($propertyId);
            $isMain = ($count === 0);

            $mediaId = $mediaModel->insert([
                'property_id' => $propertyId,
                'tipo'        => 'imagem',
                'url'         => $publicUrl,
                'ordem'       => $count + 1,
                'principal'   => $isMain,
                'created_at'  => date('Y-m-d H:i:s')
            ]);

            // 5. Update Property Score (Async-ish)
            service('rankingService')->updateScore($propertyId);

            return [
                'success' => true,
                'media' => [
                    'id' => $mediaId,
                    'url' => $storage->getPublicUrl($publicUrl),
                    'principal' => $isMain
                ]
            ];

        } catch (\Exception $e) {
            log_message('error', 'Erro ao fazer upload de mídia: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erro interno ao salvar arquivo.'];
        }
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
}
