<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\LeadModel;
use CodeIgniter\HTTP\ResponseInterface;

class LeadsController extends BaseController
{
    protected LeadModel $model;

    public function __construct()
    {
        $this->model = model(LeadModel::class);
    }

    public function index()
    {
        $service = service('leadService');
        $user = auth()->user();
        $isAdmin = $this->isGlobalAdmin($user);
        $filters = $this->leadFiltersFromRequest();

        if (!$isAdmin) {
            if (empty($user->account_id)) {
                return view('Admin/leads/index', [
                    'leads' => [],
                    'pager' => \Config\Services::pager(),
                    'isAdmin' => false,
                    'filters' => $filters,
                    'stats' => [
                        'total' => 0,
                        'today' => 0,
                        'new' => 0,
                        'in_progress' => 0,
                        'closed' => 0,
                        'lost' => 0,
                        'answer_rate' => 0,
                    ],
                ]);
            }

            $filters['account_id_anunciante'] = (int) $user->account_id;
        }

        $data = $service->listLeads($filters, 20);

        return view('Admin/leads/index', [
            'leads' => $data['leads'],
            'pager' => $data['pager'],
            'isAdmin' => $isAdmin,
            'filters' => $filters,
            'stats' => $service->getLeadStats($filters),
        ]);
    }

    public function show($id)
    {
        $service = service('leadService');
        $data = $service->getLeadWithEvents((int) $id);

        if (empty($data)) {
            return $this->jsonError('Lead não encontrado.', ResponseInterface::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessLead(auth()->user(), $data['lead'])) {
            return $this->jsonError('Você não tem permissão para acessar este lead.', ResponseInterface::HTTP_FORBIDDEN);
        }

        return $this->response->setJSON([
            'success' => true,
            'lead' => $data['lead'],
            'events' => $data['events'],
            'property' => $data['property'],
            'csrf_token' => csrf_token(),
            'csrf_hash' => csrf_hash(),
        ]);
    }

    public function updateStatus($id)
    {
        $status = (string) $this->request->getPost('status');
        $validStatuses = [
            LeadModel::STATUS_NOVO,
            LeadModel::STATUS_ATENDIMENTO,
            LeadModel::STATUS_CONCLUIDO,
            LeadModel::STATUS_PERDIDO,
        ];

        if (!in_array($status, $validStatuses, true)) {
            return $this->jsonError('Status inválido.', ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lead = $this->model->find((int) $id);
        if (!$lead) {
            return $this->jsonError('Lead não encontrado.', ResponseInterface::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessLead(auth()->user(), $lead)) {
            return $this->jsonError('Você não tem permissão para alterar este lead.', ResponseInterface::HTTP_FORBIDDEN);
        }

        if (service('leadService')->updateStatus((int) $id, $status)) {
            return $this->response->setJSON([
                'success' => true,
                'message' => 'Status atualizado.',
                'csrf_token' => csrf_token(),
                'csrf_hash' => csrf_hash(),
            ]);
        }

        return $this->jsonError('Erro ao atualizar status.', ResponseInterface::HTTP_BAD_REQUEST);
    }

    public function update($id)
    {
        $lead = $this->model->find((int) $id);
        if (!$lead) {
            return $this->jsonError('Lead não encontrado.', ResponseInterface::HTTP_NOT_FOUND);
        }

        if (!$this->canAccessLead(auth()->user(), $lead)) {
            return $this->jsonError('Você não tem permissão para alterar este lead.', ResponseInterface::HTTP_FORBIDDEN);
        }

        $fields = ['nome_visitante', 'email_visitante', 'telefone_visitante'];
        $updateData = [];
        foreach ($fields as $field) {
            if ($this->request->getPost($field) !== null) {
                $updateData[$field] = trim((string) $this->request->getPost($field));
            }
        }

        if (empty($updateData)) {
            return $this->jsonError('Nenhum dado enviado para atualização.', ResponseInterface::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (service('leadService')->updateLead((int) $id, $updateData)) {
            return $this->response->setJSON([
                'success' => true,
                'message' => 'Lead atualizado com sucesso.',
                'csrf_token' => csrf_token(),
                'csrf_hash' => csrf_hash(),
            ]);
        }

        return $this->jsonError('Erro ao atualizar lead.', ResponseInterface::HTTP_BAD_REQUEST);
    }

    private function leadFiltersFromRequest(): array
    {
        $filters = [];
        foreach (['status', 'origem', 'cidade', 'q', 'property_id'] as $field) {
            $value = trim((string) $this->request->getGet($field));
            if ($value !== '') {
                $filters[$field] = $field === 'property_id' ? (int) $value : $value;
            }
        }

        return $filters;
    }

    private function isGlobalAdmin($user): bool
    {
        return $user && method_exists($user, 'inGroup') && $user->inGroup('superadmin', 'admin');
    }

    private function canAccessLead($user, $lead): bool
    {
        if ($this->isGlobalAdmin($user)) {
            return true;
        }

        return !empty($user->account_id)
            && (int) $lead->account_id_anunciante === (int) $user->account_id;
    }

    private function jsonError(string $message, int $status)
    {
        return $this->response->setJSON([
            'success' => false,
            'message' => $message,
            'csrf_token' => csrf_token(),
            'csrf_hash' => csrf_hash(),
        ])->setStatusCode($status);
    }
}
