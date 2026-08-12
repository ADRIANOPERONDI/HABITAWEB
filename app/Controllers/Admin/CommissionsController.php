<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\IntegrationCommissionModel;
use App\Models\IntegrationCommissionRuleModel;
use App\Services\IntegrationCommissionService;

/**
 * Comissões por lead fechado.
 *
 * Duas audiências, e a diferença importa:
 *
 *  - superadmin: /admin/comissoes — vê tudo, aprova, cancela e fatura.
 *  - tenant: /admin/minhas-comissoes — extrato SOMENTE LEITURA do que ele
 *    deve. Existe para não haver surpresa na fatura; esconder isso é o caminho
 *    mais curto para uma discussão de cobrança.
 */
class CommissionsController extends BaseController
{
    private IntegrationCommissionService $service;

    public function __construct()
    {
        $this->service = new IntegrationCommissionService();
    }

    // ------------------------------------------------------------ superadmin

    public function index()
    {
        $filters = [
            'account_id' => $this->request->getGet('account_id'),
            'status'     => $this->request->getGet('status'),
            'from'       => $this->request->getGet('from'),
            'to'         => $this->request->getGet('to'),
        ];

        $data = $this->service->listFiltered(array_filter($filters));

        return view('Admin/comissoes/index', [
            'commissions' => $data['items'],
            'pager'       => $data['pager'],
            'totals'      => $this->service->totalsByStatus(),
            'filters'     => $filters,
            'accounts'    => model(\App\Models\AccountModel::class)->where('deleted_at IS NULL')->findAll(),
            'statuses'    => [
                IntegrationCommissionModel::STATUS_PENDING   => 'Aguardando aprovação',
                IntegrationCommissionModel::STATUS_APPROVED  => 'Aprovada',
                IntegrationCommissionModel::STATUS_INVOICED  => 'Faturada',
                IntegrationCommissionModel::STATUS_PAID      => 'Paga',
                IntegrationCommissionModel::STATUS_CANCELLED => 'Cancelada',
            ],
        ]);
    }

    /** POST (AJAX): aprova uma ou várias. */
    public function approve()
    {
        $ids = (array) ($this->request->getPost('ids') ?? []);

        if ($ids === []) {
            return $this->response->setJSON(['success' => false, 'message' => 'Nenhuma comissão selecionada.']);
        }

        $n = $this->service->approveMany($ids);

        audit_log('commission.approved', ['count' => $n]);

        return $this->response->setJSON([
            'success' => true,
            'message' => "{$n} comissão(ões) aprovada(s).",
        ]);
    }

    /** POST (AJAX): cancela uma comissão ainda não faturada. */
    public function cancel(int $id)
    {
        $ok = $this->service->cancel($id, (string) $this->request->getPost('reason'));

        audit_log('commission.cancelled', ['id' => $id, 'ok' => $ok]);

        return $this->response->setJSON([
            'success' => $ok,
            'message' => $ok
                ? 'Comissão cancelada.'
                : 'Só é possível cancelar comissões que ainda não foram faturadas.',
        ]);
    }

    // ---------------------------------------------------------------- regras

    public function rules()
    {
        return view('Admin/comissoes/rules', [
            'rules'    => model(IntegrationCommissionRuleModel::class)->listAll(),
            'accounts' => model(\App\Models\AccountModel::class)->where('deleted_at IS NULL')->findAll(),
        ]);
    }

    public function saveRule()
    {
        $post = $this->request->getPost();

        $model = (string) ($post['model'] ?? IntegrationCommissionRuleModel::MODEL_PERCENT);

        if (! in_array($model, [IntegrationCommissionRuleModel::MODEL_PERCENT, IntegrationCommissionRuleModel::MODEL_FIXED], true)) {
            return redirect()->back()->with('error', 'Modelo de cobrança inválido.');
        }

        $tipoNegocio = $post['tipo_negocio'] ?? '';

        if ($tipoNegocio !== '' && ! in_array($tipoNegocio, ['VENDA', 'ALUGUEL', 'TEMPORADA', 'VENDA_ALUGUEL'], true)) {
            return redirect()->back()->with('error', 'Tipo de negócio inválido.');
        }

        $data = [
            // Vazio = regra padrão da plataforma.
            'account_id'    => ($post['account_id'] ?? '') === '' ? null : (int) $post['account_id'],
            'provider_code' => ($post['provider_code'] ?? '') === '' ? null : (string) $post['provider_code'],
            'tipo_negocio'  => $tipoNegocio === '' ? null : $tipoNegocio,
            'model'         => $model,
            'value'         => max(0, (float) str_replace(',', '.', (string) ($post['value'] ?? 0))),
            'min_value'     => ($post['min_value'] ?? '') === '' ? null : (float) str_replace(',', '.', (string) $post['min_value']),
            'max_value'     => ($post['max_value'] ?? '') === '' ? null : (float) str_replace(',', '.', (string) $post['max_value']),
            'is_active'     => ! empty($post['is_active']),
            'notes'         => $post['notes'] ?? null,
        ];

        $ruleModel = model(IntegrationCommissionRuleModel::class);
        $id        = (int) ($post['id'] ?? 0);

        $id > 0 ? $ruleModel->update($id, $data) : $ruleModel->insert($data);

        audit_log('commission.rule_saved', ['id' => $id ?: 'new']);

        return redirect()->to(site_url('admin/comissoes/regras'))->with('message', 'Regra salva.');
    }

    public function deleteRule(int $id)
    {
        model(IntegrationCommissionRuleModel::class)->delete($id);

        audit_log('commission.rule_deleted', ['id' => $id]);

        return $this->response->setJSON(['success' => true, 'message' => 'Regra removida.']);
    }

    // --------------------------------------------------------------- tenant

    /** Extrato do próprio tenant, somente leitura. */
    public function mine()
    {
        $user      = auth()->user();
        $accountId = (int) ($user->account_id ?? 0);

        if ($accountId === 0) {
            return view('Admin/comissoes/mine', [
                'commissions' => [],
                'pager'       => \Config\Services::pager(),
                'totals'      => [],
            ]);
        }

        $data = $this->service->statementFor($accountId);

        return view('Admin/comissoes/mine', [
            'commissions' => $data['items'],
            'pager'       => $data['pager'],
            'totals'      => $this->service->totalsByStatus(['account_id' => $accountId]),
        ]);
    }
}
