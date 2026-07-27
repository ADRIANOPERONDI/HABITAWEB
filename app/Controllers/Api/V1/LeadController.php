<?php

namespace App\Controllers\Api\V1;

use App\Services\LeadService;
use App\Models\LeadModel;

class LeadController extends BaseController
{
    protected LeadService $leadService;
    protected LeadModel $leadModel;

    public function __construct()
    {
        $this->leadService = new LeadService();
        $this->leadModel = model(LeadModel::class);
    }

    /**
     * GET /api/v1/leads
     * Lista leads da conta autenticada
     */
    public function index()
    {
        $accountId = $this->request->auth_account_id ?? null;
        
        if (!$accountId) {
            return $this->respondForbidden('Acesso restrito a contas autenticadas.');
        }

        $filters = array_merge(
            $this->request->getGet(),
            ['account_id_anunciante' => $accountId]
        );

        $builder = $this->leadModel->where('account_id_anunciante', $accountId);
        
        // Filtros opcionais
        if (!empty($filters['property_id'])) {
            $builder->where('property_id', $filters['property_id']);
        }
        
        if (!empty($filters['status'])) {
            $builder->where('status', $filters['status']);
        }

        if (!empty($filters['data_inicio'])) {
            $builder->where('created_at >=', $filters['data_inicio']);
        }

        if (!empty($filters['data_fim'])) {
            $builder->where('created_at <=', $filters['data_fim']);
        }

        $leads = $builder->orderBy('created_at', 'DESC')->paginate(20);
        $pager = $this->leadModel->pager;

        return $this->respondSuccess([
            'leads' => $leads,
            'pagination' => [
                'current_page' => $pager->getCurrentPage(),
                'per_page' => $pager->getPerPage(),
                'total' => $pager->getTotal(),
                'last_page' => $pager->getPageCount(),
            ]
        ]);
    }

    /**
     * GET /api/v1/leads/(:id)
     */
    public function show($id = null)
    {
        if (!$id) {
            return $this->respondError('ID do lead é obrigatório.', 400);
        }

        $lead = $this->leadModel->find($id);
        
        if (!$lead) {
            return $this->respondNotFound('Lead não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->auth_account_id ?? null;
        if (!$accountId || $lead->account_id_anunciante != $accountId) {
            return $this->respondForbidden('Acesso negado a este lead.');
        }

        return $this->respondSuccess(['lead' => $lead]);
    }

    /**
     * POST /api/v1/leads
     * Cria um novo lead (PÚBLICO - não requer auth)
     */
    public function create()
    {
        $data = $this->getJsonBody();

        if ($data === null) {
            return $this->respondInvalidJson();
        }

        // Validação básica
        if (empty($data['property_id']) || empty($data['nome_visitante']) || empty($data['email_visitante'])) {
            return $this->respondError('property_id, nome_visitante e email_visitante são obrigatórios.', 400);
        }

        if (!(new \App\Services\PublicPropertyVisibilityService())->isVisible((int) $data['property_id'])) {
            return $this->respondNotFound('Imóvel não encontrado.');
        }

        // Pega account_id do imóvel
        $propertyModel = model('App\\Models\\PropertyModel');
        $property = $propertyModel->find($data['property_id']);

        if (!$property) {
            return $this->respondNotFound('Imóvel não encontrado.');
        }

        $data['account_id_anunciante'] = $property->account_id;
        $data['ip_address'] = $this->request->getIPAddress();
        $data['user_agent'] = $this->request->getUserAgent()->getAgentString();
        $data['status'] = 'novo';

        $result = $this->leadService->trySaveLead($data);

        if ($result['success']) {
            return $this->respondSuccess($result, 'Lead registrado com sucesso.', 201);
        }

        return $this->respondError($result['message'], 422, $result['errors'] ?? [], self::ERR_VALIDATION);
    }

    /**
     * PUT /api/v1/leads/(:id)
     * Atualiza um lead (ex: mudar status)
     */
    public function update($id = null)
    {
        if (!$id) {
            return $this->respondError('ID do lead é obrigatório.', 400);
        }

        $lead = $this->leadModel->find($id);
        
        if (!$lead) {
            return $this->respondNotFound('Lead não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->auth_account_id ?? null;
        if (!$accountId || $lead->account_id_anunciante != $accountId) {
            return $this->respondForbidden('Acesso negado.');
        }

        $data = $this->getJsonBody();

        if ($data === null) {
            return $this->respondInvalidJson();
        }

        // Antes chamava trySaveLead($data, $id), mas a assinatura do service é
        // trySaveLead(array $data) — o $id era descartado em silêncio e o
        // service exigia property_id, então o caso natural ("só mudar o status")
        // devolvia 400. Pior: quando property_id vinha, o service reencontrava o
        // lead por (property_id, email_visitante) e podia atualizar OUTRO
        // registro que não o da URL. Aqui o update é direto no lead do path.
        $allowed = ['status', 'nome_visitante', 'telefone_visitante', 'email_visitante',
                    'mensagem', 'user_id_responsavel', 'closing_value', 'closing_notes'];

        $updateData = array_intersect_key($data, array_flip($allowed));

        if ($updateData === []) {
            return $this->respondError(
                'Nenhum campo atualizável foi enviado. Permitidos: ' . implode(', ', $allowed) . '.',
                422,
                [],
                self::ERR_VALIDATION
            );
        }

        if (isset($updateData['status'])) {
            $statuses = [
                LeadModel::STATUS_NOVO,
                LeadModel::STATUS_ATENDIMENTO,
                LeadModel::STATUS_CONCLUIDO,
                LeadModel::STATUS_PERDIDO,
            ];

            $updateData['status'] = strtoupper((string) $updateData['status']);

            if (! in_array($updateData['status'], $statuses, true)) {
                return $this->respondError(
                    'status deve ser um de: ' . implode(', ', $statuses) . '.',
                    422,
                    [],
                    self::ERR_VALIDATION
                );
            }

            if ($updateData['status'] === LeadModel::STATUS_CONCLUIDO && empty($lead->closed_at)) {
                $updateData['closed_at'] = date('Y-m-d H:i:s');
            }
        }

        if (! $this->leadModel->update($id, $updateData)) {
            return $this->respondError(
                'Erro ao atualizar lead.',
                422,
                $this->leadModel->errors(),
                self::ERR_VALIDATION
            );
        }

        return $this->respondSuccess(['lead' => $this->leadModel->find($id)], 'Lead atualizado com sucesso.');
    }

    /**
     * DELETE /api/v1/leads/(:id)
     */
    public function delete($id = null)
    {
        if (!$id) {
            return $this->respondError('ID do lead é obrigatório.', 400);
        }

        $lead = $this->leadModel->find($id);
        
        if (!$lead) {
            return $this->respondNotFound('Lead não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->auth_account_id ?? null;
        if (!$accountId || $lead->account_id_anunciante != $accountId) {
            return $this->respondForbidden('Acesso negado.');
        }

        if ($this->leadModel->delete($id)) {
            return $this->respondSuccess(['lead_id' => (int) $id], 'Lead deletado com sucesso.');
        }

        return $this->respondError('Erro ao deletar lead.', 500, [], self::ERR_INTERNAL);
    }
}
