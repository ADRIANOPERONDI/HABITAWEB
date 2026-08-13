<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\LeadChargeModel;
use App\Models\LeadChargeRuleModel;
use App\Services\LeadChargeService;

/**
 * Cobranças por lead.
 *
 * Duas audiências, e a diferença importa:
 *
 *  - superadmin: /admin/cobrancas — vê tudo, aprova, cancela e fatura.
 *  - tenant: /admin/minhas-cobrancas — extrato SOMENTE LEITURA do que ele
 *    deve. Existe para não haver surpresa na fatura; esconder isso é o caminho
 *    mais curto para uma discussão de cobrança.
 */
class ChargesController extends BaseController
{
    private LeadChargeService $service;

    public function __construct()
    {
        $this->service = new LeadChargeService();
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

        return view('Admin/cobrancas/index', [
            'commissions' => $data['items'],
            'pager'       => $data['pager'],
            'totals'      => $this->service->totalsByStatus(),
            'filters'     => $filters,
            'accounts'    => model(\App\Models\AccountModel::class)->where('deleted_at IS NULL')->findAll(),
            'statuses'    => [
                LeadChargeModel::STATUS_PENDING   => 'Aguardando aprovação',
                LeadChargeModel::STATUS_APPROVED  => 'Aprovada',
                LeadChargeModel::STATUS_DISPUTED  => 'Contestada',
                LeadChargeModel::STATUS_INVOICED  => 'Faturada',
                LeadChargeModel::STATUS_PAID      => 'Paga',
                LeadChargeModel::STATUS_CANCELLED => 'Cancelada',
                LeadChargeModel::STATUS_WAIVED    => 'Isentada',
            ],
        ]);
    }

    /** POST (AJAX): aprova uma ou várias. */
    public function approve()
    {
        $ids = (array) ($this->request->getPost('ids') ?? []);

        if ($ids === []) {
            return $this->response->setJSON(['success' => false, 'message' => 'Nenhuma cobrança selecionada.']);
        }

        $n = $this->service->approveMany($ids);

        audit_log('lead_charge.approved', ['count' => $n]);

        return $this->response->setJSON([
            'success' => true,
            'message' => "{$n} cobrança(s) aprovada(s).",
        ]);
    }

    /** POST (AJAX): cancela uma cobrança ainda não faturada. */
    public function cancel(int $id)
    {
        $ok = $this->service->cancel($id, (string) $this->request->getPost('reason'));

        audit_log('lead_charge.cancelled', ['id' => $id, 'ok' => $ok]);

        return $this->response->setJSON([
            'success' => $ok,
            'message' => $ok
                ? 'Cobrança cancelada.'
                : 'Só é possível cancelar cobranças que ainda não foram faturadas.',
        ]);
    }

    // ---------------------------------------------------------------- regras

    public function rules()
    {
        return view('Admin/cobrancas/rules', [
            'rules'    => model(LeadChargeRuleModel::class)->listAll(),
            'accounts' => model(\App\Models\AccountModel::class)->where('deleted_at IS NULL')->findAll(),
        ]);
    }

    public function saveRule()
    {
        $post = $this->request->getPost();

        $model = (string) ($post['model'] ?? LeadChargeRuleModel::MODEL_PERCENT);

        if (! in_array($model, [LeadChargeRuleModel::MODEL_PERCENT, LeadChargeRuleModel::MODEL_FIXED], true)) {
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

        $ruleModel = model(LeadChargeRuleModel::class);
        $id        = (int) ($post['id'] ?? 0);

        $id > 0 ? $ruleModel->update($id, $data) : $ruleModel->insert($data);

        audit_log('lead_charge.rule_saved', ['id' => $id ?: 'new']);

        return redirect()->to(site_url('admin/cobrancas/regras'))->with('message', 'Regra salva.');
    }

    public function deleteRule(int $id)
    {
        model(LeadChargeRuleModel::class)->delete($id);

        audit_log('lead_charge.rule_deleted', ['id' => $id]);

        return $this->response->setJSON(['success' => true, 'message' => 'Regra removida.']);
    }

    // --------------------------------------------------------------- tenant

    /** Extrato do próprio tenant, somente leitura. */
    public function mine()
    {
        $user      = auth()->user();
        $accountId = (int) ($user->account_id ?? 0);

        if ($accountId === 0) {
            return view('Admin/cobrancas/mine', [
                'commissions' => [],
                'pager'       => \Config\Services::pager(),
                'totals'      => [],
            ]);
        }

        $data = $this->service->statementFor($accountId);

        return view('Admin/cobrancas/mine', [
            'commissions' => $data['items'],
            'pager'       => $data['pager'],
            'totals'      => $this->service->totalsByStatus(['account_id' => $accountId]),
        ]);
    }
}
