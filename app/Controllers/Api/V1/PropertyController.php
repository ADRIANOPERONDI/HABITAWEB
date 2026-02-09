<?php

namespace App\Controllers\Api\V1;

use App\Services\PropertyService;

class PropertyController extends BaseController
{
    protected PropertyService $propertyService;

    public function __construct()
    {
        $this->propertyService = new PropertyService();
    }

    /**
     * GET /api/v1/properties
     */
    public function index()
    {
        $filters = $this->request->getGet();
        $result = $this->propertyService->listProperties($filters);
        return $this->respondSuccess($result);
    }

    /**
     * POST /api/v1/properties
     */
    public function create()
    {
        $data = $this->request->getJSON(true);
        $currentAccountId = $this->request->auth_account_id;
        $isSuperAdmin     = $this->request->auth_user_id == 1;

        // Força account_id do usuário logado se não for super admin
        if (!$isSuperAdmin || !isset($data['account_id'])) {
            $data['account_id'] = $currentAccountId;
        }
        
        $result = $this->propertyService->trySaveProperty($data);

        if ($result['success']) {
            return $this->respondCreated($result);
        }

        return $this->respondError($result['message'], 400, $result['errors'] ?? []);
    }

    /**
     * PUT /api/v1/properties/(:id)
     */
    public function update($id = null)
    {
        $data = $this->request->getJSON(true);
        $result = $this->propertyService->trySaveProperty($data, $id);

        if ($result['success']) {
            return $this->respondSuccess($result);
        }

        return $this->respondError($result['message'], 400, $result['errors']);
    }

    /**
     * GET /api/v1/properties/(:id)
     */
    public function show($id = null)
    {
        if (!$id) {
            return $this->respondError('ID do imóvel é obrigatório.', 400);
        }

        $details = $this->propertyService->getPropertyDetails($id);
        
        if (!$details || !isset($details['property'])) {
            return $this->failNotFound('Imóvel não encontrado.');
        }

        // Validação de acesso: usuário só pode ver imóveis da própria conta
        $accountId = $this->request->account_id ?? null;
        if ($accountId && $details['property']->account_id != $accountId) {
            return $this->failForbidden('Acesso negado a este imóvel.');
        }

        return $this->respondSuccess($details);
    }

    /**
     * DELETE /api/v1/properties/(:id)
     */
    public function delete($id = null)
    {
        if (!$id) {
            return $this->respondError('ID do imóvel é obrigatório.', 400);
        }

        $property = model('App\\Models\\PropertyModel')->find($id);
        
        if (!$property) {
            return $this->failNotFound('Imóvel não encontrado.');
        }

        // Validação de acesso
        $accountId = $this->request->account_id ?? null;
        if ($accountId && $property->account_id != $accountId) {
            return $this->failForbidden('Acesso negado.');
        }

        if ($this->propertyService->deleteProperty($id)) {
            return $this->respondSuccess(['message' => 'Imóvel desativado com sucesso.']);
        }

        return $this->respondError('Erro ao desativar imóvel.', 500);
    }


    /**
     * POST /api/v1/properties/(:id)/report
     */
    public function report($id = null)
    {
        if (!$id) {
            return $this->respondError('ID do imóvel é obrigatório.', 400);
        }

        $input = $this->request->getJSON(true);
        $reason = $input['reason'] ?? '';
        $type   = $input['type'] ?? 'WRONG_INFO';

        if (empty($reason)) {
            return $this->respondError('O motivo da denúncia é obrigatório.', 400);
        }

        $reportModel = \CodeIgniter\Config\Factories::models(\App\Models\PropertyReportModel::class);
        
        $data = [
            'property_id' => $id,
            'user_id'     => auth()->id(), // null if guest
            'ip_address'  => $this->request->getIPAddress(),
            'reason'      => $reason,
            'type'        => $type,
            'status'      => 'PENDING'
        ];

        if ($reportModel->insert($data)) {
            return $this->respondSuccess(['message' => 'Denúncia recebida com sucesso.']);
        }

        return $this->respondError('Erro ao salvar denúncia.', 500);
    }

    /**
     * POST /api/v1/properties/calculate-score
     * Calculates score based on draft data.
     */
    public function calculateScore()
    {
        $data = $this->request->getJSON(true);
        $property = new \App\Entities\Property($data);
        
        // Se vier media_count no payload, usa, senão tenta contar do banco se tiver ID
        $mediaCount = $data['media_count'] ?? 0;
        // Se tiver ID no payload mas não media_count, tenta buscar do banco
        if (!empty($data['id']) && $mediaCount == 0) {
            // Using PropertyMediaModel for counting
            $mediaModel = model('App\Models\PropertyMediaModel');
            $mediaCount = $mediaModel->countByProperty($data['id']);
        }

        $curationService = new \App\Services\CurationService();
        $result = $curationService->calculateDetailedScore($property, $mediaCount);
        
        return $this->respondSuccess($result);
    }
    /**
     * POST /api/v1/properties/(:id)/media
     * Upload de imagem para o imóvel
     */
    public function uploadMedia($id = null)
    {
        if (!$id) {
            return $this->respondError('ID do imóvel é obrigatório.', 400);
        }

        // Validação de acesso
        $propertyModel = model('App\\Models\\PropertyModel');
        $property = $propertyModel->find($id);

        if (!$property) {
            return $this->failNotFound('Imóvel não encontrado.');
        }

        $accountId = $this->request->account_id ?? null;
        if ($accountId && $property->account_id != $accountId) {
            return $this->failForbidden('Acesso negado.');
        }

        $file = $this->request->getFile('file');

        if (!$file) {
            return $this->respondError('Arquivo não enviado.', 400);
        }

        $result = $this->propertyService->addMedia($id, $file);

        if ($result['success']) {
            return $this->respondCreated($result);
        }

        return $this->respondError($result['message'], 400);
    }

    /**
     * DELETE /api/v1/properties/(:id)/media/(:mediaId)
     * Remove uma imagem do imóvel
     */
    public function deleteMedia($id = null, $mediaId = null)
    {
        if (!$id || !$mediaId) {
            return $this->respondError('IDs obrigatórios.', 400);
        }

        // Validação de acesso
        $propertyModel = model('App\\Models\\PropertyModel');
        $property = $propertyModel->find($id);

        if (!$property) {
            return $this->failNotFound('Imóvel não encontrado.');
        }

        $accountId = $this->request->account_id ?? null;
        if ($accountId && $property->account_id != $accountId) {
            return $this->failForbidden('Acesso negado.');
        }

        $result = $this->propertyService->deleteMedia($id, $mediaId);

        if ($result['success']) {
            return $this->respondSuccess($result);
        }

        return $this->respondError($result['message'], 400);
    }

    /**
     * POST /api/v1/properties/(:id)/media/(:mediaId)/main
     * Define imagem como capa
     */
    public function setMainMedia($id = null, $mediaId = null)
    {
        if (!$id || !$mediaId) {
            return $this->respondError('IDs obrigatórios.', 400);
        }

        // Validação de acesso
        $propertyModel = model('App\\Models\\PropertyModel');
        $property = $propertyModel->find($id);

        if (!$property) {
            return $this->failNotFound('Imóvel não encontrado.');
        }

        $accountId = $this->request->account_id ?? null;
        if ($accountId && $property->account_id != $accountId) {
            return $this->failForbidden('Acesso negado.');
        }

        $result = $this->propertyService->setMainMedia($id, $mediaId);

        if ($result['success']) {
            return $this->respondSuccess($result);
        }

        return $this->respondError($result['message'], 400);
    }
}
