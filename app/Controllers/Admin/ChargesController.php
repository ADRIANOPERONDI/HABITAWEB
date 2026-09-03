<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\LeadChargeModel;
use App\Models\LeadChargeRuleModel;
use App\Services\LeadChargeService;
use App\Services\LeadCreditService;

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

        // FIXED não usa piso/teto: o valor já É o único número da regra —
        // gravar min/max junto só criaria uma segunda forma de mudar o
        // resultado de calculate() que a tela nem mostra pra esse modelo.
        $data = [
            // Vazio = regra padrão da plataforma.
            'account_id'    => ($post['account_id'] ?? '') === '' ? null : (int) $post['account_id'],
            'provider_code' => ($post['provider_code'] ?? '') === '' ? null : (string) $post['provider_code'],
            'tipo_negocio'  => $tipoNegocio === '' ? null : $tipoNegocio,
            'model'         => $model,
            'value'         => max(0, (float) str_replace(',', '.', (string) ($post['value'] ?? 0))),
            'min_value'     => $model === LeadChargeRuleModel::MODEL_PERCENT && ($post['min_value'] ?? '') !== ''
                ? (float) str_replace(',', '.', (string) $post['min_value'])
                : null,
            'max_value'     => $model === LeadChargeRuleModel::MODEL_PERCENT && ($post['max_value'] ?? '') !== ''
                ? (float) str_replace(',', '.', (string) $post['max_value'])
                : null,
            'is_active'     => ! empty($post['is_active']),
            'valid_from'    => ($post['valid_from'] ?? '') === '' ? null : (string) $post['valid_from'],
            'valid_to'      => ($post['valid_to'] ?? '') === '' ? null : (string) $post['valid_to'],
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
        $periodo   = $this->periodoFromRequest();

        // Regras vigentes da plataforma, pro painel "Como funciona" — o
        // tenant vê de onde vem o valor que está pagando, não só o total.
        $regrasPlataforma = model(LeadChargeRuleModel::class)->platformDefaults();

        if ($accountId === 0) {
            return view('Admin/cobrancas/mine', [
                'commissions'      => [],
                'pager'            => \Config\Services::pager(),
                'totals'           => [],
                'periodo'          => $periodo,
                'periodoOpcoes'    => $this->ultimosPeriodos(),
                'projetado'        => 0.0,
                'creditoAtual'     => 0.0,
                'regrasPlataforma' => $regrasPlataforma,
            ]);
        }

        $data = $this->service->statementFor($accountId, $periodo);

        return view('Admin/cobrancas/mine', [
            'commissions'      => $data['items'],
            'pager'            => $data['pager'],
            'totals'           => $this->service->totalsByStatus(['account_id' => $accountId, 'periodo' => $periodo]),
            'periodo'          => $periodo,
            'periodoOpcoes'    => $this->ultimosPeriodos(),
            'projetado'        => model(LeadChargeModel::class)->projectedTotalFor($accountId, $periodo),
            'creditoAtual'     => (new LeadCreditService())->balanceFor($accountId, $periodo),
            'regrasPlataforma' => $regrasPlataforma,
        ]);
    }

    /** Últimos 12 meses (incluindo o corrente), mais recente primeiro — opções do filtro de período. */
    private function ultimosPeriodos(): array
    {
        $opcoes = [];

        for ($i = 0; $i < 12; $i++) {
            $data = date('Y-m-01', strtotime("-{$i} months"));
            $opcoes[$data] = \CodeIgniter\I18n\Time::parse($data)->format('M/Y');
        }

        return $opcoes;
    }

    /**
     * `periodo` vem da URL como `Y-m` (select de mês); a coluna no banco é o
     * primeiro dia do mês. Formato inválido ou ausente cai no mês corrente —
     * nunca estoura pra um erro de SQL por causa de um parâmetro de GET.
     */
    private function periodoFromRequest(): string
    {
        $raw = (string) ($this->request->getGet('periodo') ?? '');

        if (preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
            return $raw . '-01';
        }

        return date('Y-m-01');
    }

    /** POST (AJAX): o tenant contesta uma cobrança própria ainda dentro do prazo. */
    public function contest(int $id)
    {
        $accountId = (int) (auth()->user()->account_id ?? 0);
        $reason    = (string) $this->request->getPost('reason');

        if ($accountId === 0 || trim($reason) === '') {
            return $this->response->setJSON(['success' => false, 'message' => 'Informe o motivo da contestação.']);
        }

        $ok = $this->service->contest($id, $accountId, $reason);

        audit_log('lead_charge.contested', ['id' => $id, 'account_id' => $accountId, 'ok' => $ok]);

        return $this->response->setJSON([
            'success' => $ok,
            'message' => $ok
                ? 'Contestação registrada. Um responsável vai analisar.'
                : 'Não foi possível contestar — a cobrança já pode ter sido aprovada.',
        ]);
    }

    /** POST (AJAX, superadmin): resolve uma disputa. */
    public function resolveDispute(int $id)
    {
        $procedente = (bool) $this->request->getPost('procedente');
        $notes      = (string) $this->request->getPost('notes');

        $ok = $this->service->resolveDispute($id, $procedente, $notes !== '' ? $notes : null);

        audit_log('lead_charge.dispute_resolved', ['id' => $id, 'procedente' => $procedente, 'ok' => $ok]);

        return $this->response->setJSON([
            'success' => $ok,
            'message' => $ok ? 'Disputa resolvida.' : 'Não foi possível resolver — a cobrança não está em disputa.',
        ]);
    }
}
