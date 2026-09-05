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
            // Estado do envio ao CRM da plataforma integrada, indexado por lead.
            // Vem de uma consulta só para a página inteira — buscar por lead
            // dentro do laço da view seria N+1.
            'crmStatus' => $this->crmStatusFor($data['leads']),
            // Cobrança por lead (D3), mesma lógica de lote.
            'charges'   => $this->chargesFor($data['leads']),
        ]);
    }

    /**
     * Estado do envio ao CRM externo, por lead.
     *
     * @return array<int, \App\Entities\IntegrationOutboxItem>
     */
    private function crmStatusFor(array $leads): array
    {
        $ids = array_filter(array_map(static fn ($lead) => (int) ($lead->id ?? 0), $leads));

        if ($ids === []) {
            return [];
        }

        $rows = model(\App\Models\IntegrationOutboxModel::class)
            ->where('event', \App\Models\IntegrationOutboxModel::EVENT_LEAD_CREATED)
            ->whereIn('reference_id', $ids)
            ->orderBy('id', 'ASC')
            ->findAll();

        $indexed = [];

        foreach ($rows as $row) {
            $indexed[(int) $row->reference_id] = $row;
        }

        return $indexed;
    }

    /** Cobrança de cada lead da página, indexada por lead_id — mesmo padrão de lote de crmStatusFor(). */
    private function chargesFor(array $leads): array
    {
        $ids = array_filter(array_map(static fn ($lead) => (int) ($lead->id ?? 0), $leads));

        return $ids === [] ? [] : model(\App\Models\LeadChargeModel::class)->findByLeadIds($ids);
    }

    /**
     * POST: reenvia ao CRM um lead cujo envio falhou.
     *
     * Confere a posse pelo lead, e não pelo item da fila: assim um id de outbox
     * de outro tenant não é reenviável nem por engano.
     */
    public function retryCrm(int $leadId)
    {
        $lead = model(\App\Models\LeadModel::class)->find($leadId);
        $user = auth()->user();

        if ($lead === null) {
            return $this->response->setStatusCode(404)
                ->setJSON(['success' => false, 'message' => 'Lead não encontrado.']);
        }

        if (! $this->isGlobalAdmin($user) && (int) $lead->account_id_anunciante !== (int) $user->account_id) {
            return $this->response->setStatusCode(403)
                ->setJSON(['success' => false, 'message' => 'Acesso negado a este lead.']);
        }

        $outboxService = new \App\Services\IntegrationOutboxService();
        $item          = $outboxService->leadStatus($leadId);

        if ($item === null) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Este lead não tem envio ao CRM registrado.',
            ]);
        }

        $outboxService->retry((int) $item->id);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Reenvio agendado. O lead será entregue no próximo ciclo.',
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
