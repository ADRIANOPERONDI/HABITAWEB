<?php

namespace App\Services;

use App\Entities\Lead;
use App\Entities\LeadEvent;
use App\Models\LeadModel;
use App\Models\LeadEventModel;
use App\Models\PropertyModel;
use CodeIgniter\Config\Factories;

class LeadService
{
    protected LeadModel $leadModel;
    protected LeadEventModel $leadEventModel;
    protected PropertyModel $propertyModel;

    public function __construct()
    {
        $this->leadModel      = Factories::models(LeadModel::class);
        $this->leadEventModel = Factories::models(LeadEventModel::class);
        $this->propertyModel  = Factories::models(PropertyModel::class);
    }

    /**
     * Registra um novo lead ou atualiza existente (ex: mesmo email/telefone para mesmo imóvel).
     *
     * @param array $data
     * @return array
     */
    public function trySaveLead(array $data): array
    {
        if (empty($data['property_id'])) {
            return [
                'success' => false,
                'data'    => null,
                'errors'  => ['property_id' => 'Imóvel não informado.'],
                'message' => 'Não foi possível identificar o imóvel do lead.',
            ];
        }

        $property = $this->propertyModel->find($data['property_id']);
        if (!$property) {
            return [
                'success' => false,
                'data'    => null,
                'errors'  => ['property_id' => 'Imóvel não encontrado.'],
                'message' => 'Imóvel não encontrado para registrar o lead.',
            ];
        }

        // Verifica se já existe lead desse visitante para este imóvel recentemente (opcional, para evitar spam)
        // Por simplificação, vamos criar sempre ou buscar pelo email/telefone para o mesmo imóvel
        
        $existingLead = null;
        if (!empty($data['email_visitante']) && !empty($data['property_id'])) {
            $existingLead = $this->leadModel
                ->where('property_id', $data['property_id'])
                ->where('email_visitante', $data['email_visitante'])
                ->first();
        } elseif (!empty($data['property_id']) && ($data['nome_visitante'] ?? '') === 'Visitante WhatsApp') {
            // Deduplicação para silent tracking (cliques repetidos do mesmo dispositivo/visitante)
            // Busca um lead genérico para o mesmo imóvel nos últimos 30 minutos
            $limitTime = date('Y-m-d H:i:s', strtotime('-30 minutes'));
            $existingLead = $this->leadModel
                ->where('property_id', $data['property_id'])
                ->where('nome_visitante', 'Visitante WhatsApp')
                ->where('created_at >=', $limitTime)
                ->orderBy('created_at', 'DESC')
                ->first();
        }

        $lead = $existingLead ?? new Lead();
        $data['origem'] = $data['origem'] ?? 'SITE';
        $data['tipo_lead'] = $data['tipo_lead'] ?? 'MSG';
        $data['status'] = $data['status'] ?? LeadModel::STATUS_NOVO;
        $lead->fill($data);

        $lead->account_id_anunciante = $property->account_id;
        $lead->user_id_responsavel   = $property->user_id_responsavel;
        
        if (empty($lead->status)) {
            $lead->status = 'NOVO';
        }
        if (empty($lead->tipo_lead)) {
            $lead->tipo_lead = 'MSG';
        }

        // Evita erro "There is no data to update" se for um lead existente sem alterações
        if (!$lead->hasChanged()) {
            return [
                'success' => true,
                'data'    => $lead,
                'errors'  => [],
                'message' => 'Lead já registrado sem alterações.',
            ];
        }

        if ($this->leadModel->save($lead)) {
            $leadId = $lead->id ?? $this->leadModel->getInsertID();
            $savedLead = $this->leadModel->find($leadId);
            
            // Incrementa contador de leads no imóvel
            if (!$existingLead) {
                $this->incrementPropertyLeadCount($savedLead->property_id);
                $this->registerEvent((int) $leadId, 'lead.created', [
                    'origin'    => $savedLead->origem,
                    'type'      => $savedLead->tipo_lead,
                    'timestamp' => date('Y-m-d H:i:s'),
                ]);
                
                // --- NOTIFICAÇÃO ---
                try {
                    $notificationService = new \App\Services\NotificationService();
                    $accountModel = model('App\Models\AccountModel');
                    $anunciante = $accountModel->find($savedLead->account_id_anunciante);

                    if ($anunciante) {
                        $leadNotifyData = [
                            'nome'     => $savedLead->nome_visitante,
                            'email'    => $savedLead->email_visitante,
                            'telefone' => $savedLead->telefone_visitante,
                            'mensagem' => $savedLead->mensagem
                        ];
                        
                        $anuncianteNotifyData = [
                            'email'    => $anunciante->email,
                            'telefone' => $anunciante->telefone ?? $anunciante->whatsapp
                        ];

                        $notificationService->notifyNewLead($leadNotifyData, $anuncianteNotifyData);
                    }
                } catch (\Exception $e) {
                    log_message('error', 'Erro ao processar notificação de lead: ' . $e->getMessage());
                }
                // -------------------

                // Dispara Webhook
                try {
                    service('webhookService')->dispatch('lead.created', $savedLead->toArray(), $savedLead->account_id_anunciante);
                } catch (\Throwable $e) {
                    log_message('error', 'Erro ao disparar webhook de lead: ' . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'data'    => $savedLead,
                'errors'  => [],
                'message' => 'Lead registrado com sucesso.',
            ];
        }

        return [
            'success' => false,
            'data'    => $lead,
            'errors'  => $this->leadModel->errors(),
            'message' => 'Erro ao registrar lead.',
        ];
    }

    /**
     * Registra um evento para um lead (ex: clicou no whatsapp, visualizou telefone).
     *
     * @param int $leadId
     * @param string $evento
     * @param array|null $payload
     * @return bool
     */
    public function registerEvent(int $leadId, string $evento, ?array $payload = null): bool
    {
        $event = new LeadEvent();
        $event->lead_id = $leadId;
        $event->evento  = $evento;
        $event->payload = $payload ? json_encode($payload) : null;

        return $this->leadEventModel->save($event);
    }

    /**
     * Retorna detalhes de um lead com seus eventos.
     */
    public function getLeadWithEvents(int $id): array
    {
        $lead = $this->leadModel->find($id);
        if (!$lead) return [];

        $events = $this->leadEventModel->where('lead_id', $id)
            ->orderBy('created_at', 'DESC')
            ->findAll();

        $property = null;
        if ($lead->property_id) {
            $property = $this->propertyModel
                ->select('id, titulo, bairro, cidade, preco, tipo_negocio')
                ->select('(SELECT url FROM property_media WHERE property_media.property_id = properties.id AND property_media.deleted_at IS NULL ORDER BY principal DESC, ordem ASC LIMIT 1) as cover_image')
                ->find($lead->property_id);
        }

        return [
            'lead'     => $lead,
            'events'   => $events,
            'property' => $property
        ];
    }

    protected function incrementPropertyLeadCount(int $propertyId)
    {
        // Maneira atômica ou simples de incrementar
        $property = $this->propertyModel->find($propertyId);
        if ($property) {
            $property->leads_count = ($property->leads_count ?? 0) + 1;
            $this->propertyModel->save($property);
        }
    }

    public function listLeads(array $filters = [], int $perPage = 20): array
    {
        $builder = $this->leadModel->select('leads.*, accounts.nome as advertiser_name, accounts.tipo_conta as advertiser_type')
                                   ->select('properties.titulo as property_title, properties.cidade as property_city')
                                   ->join('accounts', 'accounts.id = leads.account_id_anunciante', 'left')
                                   ->join('properties', 'properties.id = leads.property_id', 'left')
                                   ->orderBy('leads.created_at', 'DESC');

        if (isset($filters['account_id_anunciante'])) {
            $builder->where('leads.account_id_anunciante', $filters['account_id_anunciante']);
        }

        if (!empty($filters['status'])) {
            $builder->where('leads.status', $filters['status']);
        }

        if (!empty($filters['origem'])) {
            $builder->where('leads.origem', $filters['origem']);
        }

        if (!empty($filters['cidade'])) {
            $builder->where('properties.cidade', $filters['cidade']);
        }

        if (!empty($filters['property_id'])) {
            $builder->where('leads.property_id', (int) $filters['property_id']);
        }

        if (!empty($filters['q'])) {
            $term = trim($filters['q']);
            $builder->groupStart()
                ->like('leads.nome_visitante', $term)
                ->orLike('leads.email_visitante', $term)
                ->orLike('leads.telefone_visitante', $term)
                ->orLike('properties.titulo', $term)
                ->groupEnd();
        }

        return [
            'leads' => $builder->paginate($perPage),
            'pager' => $this->leadModel->pager,
        ];
    }

    public function getLeadStats(array $filters = []): array
    {
        $base = static function () use ($filters) {
            $model = new LeadModel();
            $builder = $model->builder();
            $builder->join('properties', 'properties.id = leads.property_id', 'left');

            if (isset($filters['account_id_anunciante'])) {
                $builder->where('leads.account_id_anunciante', $filters['account_id_anunciante']);
            }
            if (!empty($filters['origem'])) {
                $builder->where('leads.origem', $filters['origem']);
            }
            if (!empty($filters['cidade'])) {
                $builder->where('properties.cidade', $filters['cidade']);
            }
            if (!empty($filters['property_id'])) {
                $builder->where('leads.property_id', (int) $filters['property_id']);
            }
            if (!empty($filters['q'])) {
                $term = trim($filters['q']);
                $builder->groupStart()
                    ->like('leads.nome_visitante', $term)
                    ->orLike('leads.email_visitante', $term)
                    ->orLike('leads.telefone_visitante', $term)
                    ->orLike('properties.titulo', $term)
                    ->groupEnd();
            }

            return $builder;
        };

        $countStatus = static fn (string $status): int => (int) $base()->where('leads.status', $status)->countAllResults();
        $total = (int) $base()->countAllResults();
        $today = (int) $base()->where('leads.created_at >=', date('Y-m-d 00:00:00'))->countAllResults();
        $answered = $countStatus(LeadModel::STATUS_ATENDIMENTO) + $countStatus(LeadModel::STATUS_CONCLUIDO);

        return [
            'total'       => $total,
            'today'       => $today,
            'new'         => $countStatus(LeadModel::STATUS_NOVO),
            'in_progress' => $countStatus(LeadModel::STATUS_ATENDIMENTO),
            'closed'      => $countStatus(LeadModel::STATUS_CONCLUIDO),
            'lost'        => $countStatus(LeadModel::STATUS_PERDIDO),
            'answer_rate' => $total > 0 ? round(($answered / $total) * 100) : 0,
        ];
    }

    /**
     * Atualiza o status de um lead.
     */
    public function updateStatus(int $leadId, string $newStatus, ?array $additionalData = null): bool
    {
        $lead = $this->leadModel->find($leadId);
        if (!$lead) {
            return false;
        }

        if ($lead->status === $newStatus) {
            return true;
        }

        $lead->status = $newStatus;
        
        if ($newStatus === LeadModel::STATUS_CONCLUIDO) {
            $lead->closed_at = date('Y-m-d H:i:s');
            if (isset($additionalData['closing_value'])) {
                $lead->closing_value = $additionalData['closing_value'];
            }
            if (isset($additionalData['closing_notes'])) {
                $lead->closing_notes = $additionalData['closing_notes'];
            }
        }

        if ($this->leadModel->save($lead)) {
            // Registra evento de mudança de status
            $this->registerEvent($leadId, 'status_changed', [
                'new_status' => $newStatus,
                'timestamp'  => date('Y-m-d H:i:s')
            ]);
            
            return true;
        }

        return false;
    }

    public function updateLead(int $id, array $data): bool
    {
        $lead = $this->leadModel->find($id);
        if (!$lead) return false;

        $lead->fill($data);
        
        if ($lead->hasChanged()) {
            return $this->leadModel->save($lead);
        }

        return true;
    }
}
