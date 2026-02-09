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
        $lead->fill($data);

        // Se for novo, tenta preencher account_id_anunciante e user_id_responsavel via property
        if (!$lead->id && !empty($data['property_id'])) {
            $property = $this->propertyModel->find($data['property_id']);
            if ($property) {
                $lead->account_id_anunciante = $property->account_id;
                $lead->user_id_responsavel   = $property->user_id_responsavel;
            }
        }
        
        if (empty($lead->status)) {
            $lead->status = 'NOVO';
        }
        if (empty($lead->tipo_lead)) {
            $lead->tipo_lead = 'MSG';
        }

        if ($this->leadModel->save($lead)) {
            $leadId = $lead->id ?? $this->leadModel->getInsertID();
            $savedLead = $this->leadModel->find($leadId);
            
            // Incrementa contador de leads no imóvel
            if (!$existingLead) {
                $this->incrementPropertyLeadCount($savedLead->property_id);
                
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
                service('webhookService')->dispatch('lead.created', $savedLead->toArray(), $savedLead->account_id_anunciante);
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
                                   ->join('accounts', 'accounts.id = leads.account_id_anunciante', 'left')
                                   ->orderBy('leads.created_at', 'DESC');

        if (isset($filters['account_id_anunciante'])) {
            $builder->where('account_id_anunciante', $filters['account_id_anunciante']);
        }

        if (!empty($filters['status'])) {
            $builder->where('status', $filters['status']);
        }

        return [
            'leads' => $builder->paginate($perPage),
            'pager' => $this->leadModel->pager,
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
