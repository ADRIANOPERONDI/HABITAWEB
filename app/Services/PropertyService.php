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
     * @return array
     */
    public function trySaveProperty(array $data, ?int $id = null): array
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
            if ($property->status === 'ACTIVE') {
                $limitCheck = $this->checkPlanLimits((int)$property->account_id, $id);
                if (!$limitCheck['allowed']) {
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
                log_message('emergency', '[PropertyService] SAVE ERROR: ' . json_encode($this->propertyModel->errors()));
                return [
                    'success' => false,
                    'data'    => $property,
                    'errors'  => $this->propertyModel->errors(),
                    'message' => 'Falha na persistência dos dados.',
                ];
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
    public function checkPlanLimits(int $accountId, ?int $currentPropertyId = null): array
    {
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
        $builder->select('properties.*')
                ->where('properties.status', 'ACTIVE');

        // Joins para buscar dados do Plano + Assinatura (WEIGHTED SORT)
        $builder->join('accounts', 'accounts.id = properties.account_id', 'left')
                ->join('subscriptions', "subscriptions.account_id = accounts.id AND subscriptions.status IN ('ACTIVE', 'TRIAL')", 'left')
                ->join('plans', 'plans.id = subscriptions.plan_id', 'left')
                ->groupBy('properties.id')
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
     * Retorna imóveis que possuem destaque ou turbo ativos (Patrocinados).
     */
    public function getSponsoredProperties(int $limit = 4): array
    {
        $builder = $this->propertyModel->builder();
        $builder->select('properties.*')
                ->where('properties.status', 'ACTIVE')
                ->groupStart()
                    ->where('properties.is_destaque', true)
                    ->orWhere('properties.highlight_level >', 0)
                ->groupEnd();

        // Ordenação aleatória para dar chance a todos os patrocinados
        $builder->orderBy('RANDOM()');
                
        $properties = $builder->get($limit)->getResult(\App\Entities\Property::class);

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
        $builder = $this->propertyModel->select('properties.*, accounts.tipo_conta as account_type, accounts.nome as account_name, accounts.logo as account_logo')
                                       ->select('(SELECT url FROM property_media WHERE property_media.property_id = properties.id AND property_media.deleted_at IS NULL ORDER BY principal DESC, ordem ASC LIMIT 1) as cover_image')
                                       ->join('accounts', 'accounts.id = properties.account_id', 'left');

        // Joins para buscar dados do Plano + Assinatura
        $builder->join('subscriptions', "subscriptions.account_id = accounts.id AND subscriptions.status IN ('ACTIVE', 'TRIAL')", 'left')
                ->join('plans', 'plans.id = subscriptions.plan_id', 'left')
                ->groupBy('properties.id')
                ->groupBy('accounts.tipo_conta')  // Required for SELECT in Postgres
                ->groupBy('accounts.nome')        // Required for SELECT
                ->groupBy('accounts.logo')        // Required for SELECT
                ->groupBy('plans.preco_mensal');  // Required for ORDER BY

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
        
        if (!empty($filters['cidade'])) {
            $builder->like('properties.cidade', $filters['cidade'], 'both'); 
        }

        if (!empty($filters['bairro'])) {
            $builder->like('properties.bairro', $filters['bairro'], 'both');
        }
        
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
     * Busca detalhes completos de um imóvel (incluindo mídias).
     */
    public function getPropertyDetails(int $id): ?array
    {
        $property = $this->propertyModel->select('properties.*, accounts.nome as account_name, accounts.telefone as account_phone, accounts.whatsapp as account_whatsapp, accounts.email as account_email, accounts.creci as account_creci, accounts.tipo_conta as account_type, accounts.logo as account_logo, accounts.whatsapp_hub_config, accounts.whatsapp_messages_config, clients.nome as client_name')
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
     */
    public function incrementVisit(int $id): void
    {
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
    public function canMarkAsDestaque(int $accountId, ?int $currentPropertyId = null): array
    {
        log_message('emergency', "[canMarkAsDestaque] Início - accountId: {$accountId}, currentPropertyId: " . ($currentPropertyId ?? 'null'));
        
        // 1. Busca ASSINATURA ATIVA
        $subscription = $this->subscriptionModel
            ->where('account_id', $accountId)
            ->whereIn('status', ['ACTIVE', 'TRIAL'])
            ->orderBy('id', 'DESC')
            ->first();

        log_message('emergency', "[canMarkAsDestaque] Subscription encontrada: " . ($subscription ? "ID {$subscription->id}, Status: {$subscription->status}, Plan ID: {$subscription->plan_id}" : 'NENHUMA'));

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
        return [
            'cidades' => $this->propertyModel->distinct()->select('cidade')->where('status', 'ACTIVE')->orderBy('cidade', 'ASC')->findAll(),
            'bairros' => $this->propertyModel->distinct()->select('bairro')->where('status', 'ACTIVE')->orderBy('bairro', 'ASC')->findAll(),
            'tipos'   => $this->propertyModel->distinct()->select('tipo_imovel')->where('status', 'ACTIVE')->orderBy('tipo_imovel', 'ASC')->findAll(),
        ];
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
}
