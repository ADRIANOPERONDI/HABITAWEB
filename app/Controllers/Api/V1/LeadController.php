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
        $accountId = $this->request->account_id ?? null;
        
        if (!$accountId) {
            return $this->failForbidden('Acesso restrito a contas autenticadas.');
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
            return $this->failNotFound('Lead não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->account_id ?? null;
        if ($accountId && $lead->account_id_anunciante != $accountId) {
            return $this->failForbidden('Acesso negado a este lead.');
        }

        return $this->respondSuccess(['lead' => $lead]);
    }

    /**
     * POST /api/v1/leads
     * Cria um novo lead (PÚBLICO - não requer auth)
     */
    public function create()
    {
        $data = $this->request->getJSON(true);

        // Validação básica
        if (empty($data['property_id']) || empty($data['nome_visitante']) || empty($data['email_visitante'])) {
            return $this->respondError('property_id, nome_visitante e email_visitante são obrigatórios.', 400);
        }

        // Pega account_id do imóvel
        $propertyModel = model('App\\Models\\PropertyModel');
        $property = $propertyModel->find($data['property_id']);

        if (!$property) {
            return $this->respondError('Imóvel não encontrado.', 404);
        }

        $data['account_id_anunciante'] = $property->account_id;
        $data['ip_address'] = $this->request->getIPAddress();
        $data['user_agent'] = $this->request->getUserAgent()->getAgentString();
        $data['status'] = 'novo';

        $result = $this->leadService->trySaveLead($data);

        if ($result['success']) {
            return $this->respondCreated($result);
        }

        return $this->respondError($result['message'], 400, $result['errors'] ?? []);
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
            return $this->failNotFound('Lead não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->account_id ?? null;
        if ($accountId && $lead->account_id_anunciante != $accountId) {
            return $this->failForbidden('Acesso negado.');
        }

        $data = $this->request->getJSON(true);
        $data['id'] = $id;

        $result = $this->leadService->trySaveLead($data, $id);

        if ($result['success']) {
            return $this->respondSuccess($result);
        }

        return $this->respondError($result['message'], 400, $result['errors'] ?? []);
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
            return $this->failNotFound('Lead não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->account_id ?? null;
        if ($accountId && $lead->account_id_anunciante != $accountId) {
            return $this->failForbidden('Acesso negado.');
        }

        if ($this->leadModel->delete($id)) {
            return $this->respondSuccess(['message' => 'Lead deletado com sucesso.']);
        }

        return $this->respondError('Erro ao deletar lead.', 500);
    }
}
