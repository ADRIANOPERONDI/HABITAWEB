<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Cobranças<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Cobranças por lead<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .panel-card { border: 1px solid #f0f0f0; border-radius: 16px; background: #fff; }
    .kpi { border: 1px solid #f0f0f0; border-radius: 12px; padding: 14px 16px; background: #fff; }
    .kpi .valor { font-size: 1.25rem; font-weight: 700; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php $brl = static fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.'); ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0">
        Geradas automaticamente a cada lead recebido, conforme a regra vigente por conta/tipo de negócio.
    </p>
    <a href="<?= site_url('admin/cobrancas/regras') ?>" class="btn btn-outline-primary btn-sm">
        <i class="fa-solid fa-percent me-1"></i> Regras de cobrança
    </a>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($statuses as $key => $label): ?>
        <?php $t = $totals[$key] ?? ['count' => 0, 'total' => 0]; ?>
        <div class="col-6 col-lg">
            <div class="kpi">
                <div class="text-muted small"><?= esc($label) ?></div>
                <div class="valor"><?= $brl($t['total']) ?></div>
                <div class="text-muted small"><?= (int) $t['count'] ?> lead(s)</div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="panel-card p-4">
    <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-md-3">
            <label class="form-label small text-muted">Conta</label>
            <select name="account_id" class="form-select form-select-sm">
                <option value="">Todas</option>
                <?php foreach ($accounts as $a): ?>
                    <option value="<?= (int) $a->id ?>" <?= (string) ($filters['account_id'] ?? '') === (string) $a->id ? 'selected' : '' ?>>
                        <?= esc($a->nome) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-muted">Situação</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">Todas</option>
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?= esc($key, 'attr') ?>" <?= ($filters['status'] ?? '') === $key ? 'selected' : '' ?>>
                        <?= esc($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted">De</label>
            <input type="date" name="from" class="form-control form-control-sm" value="<?= esc($filters['from'] ?? '', 'attr') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted">Até</label>
            <input type="date" name="to" class="form-control form-control-sm" value="<?= esc($filters['to'] ?? '', 'attr') ?>">
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-primary btn-sm flex-grow-1">Filtrar</button>
            <a href="<?= site_url('admin/cobrancas') ?>" class="btn btn-outline-secondary btn-sm">Limpar</a>
        </div>
    </form>

    <?php if ($commissions === []): ?>
        <p class="text-muted mb-0">Nenhuma cobrança gerada com esses filtros.</p>
    <?php else: ?>
        <div class="d-flex justify-content-end mb-2">
            <button type="button" class="btn btn-success btn-sm" id="btnAprovar" disabled>
                <i class="fa-solid fa-check me-1"></i> Aprovar selecionadas
            </button>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th style="width: 32px;"><input type="checkbox" id="chkTodas" class="form-check-input"></th>
                        <th>Em</th>
                        <th>Conta</th>
                        <th>Lead</th>
                        <th>Negócio</th>
                        <th>Origem</th>
                        <th class="text-end">Valor</th>
                        <th>Situação</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($commissions as $c): ?>
                        <tr>
                            <td>
                                <?php if ($c->status === \App\Models\LeadChargeModel::STATUS_PENDING): ?>
                                    <input type="checkbox" class="form-check-input chk-comissao" value="<?= (int) $c->id ?>">
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <?= esc(\CodeIgniter\I18n\Time::parse((string) ($c->closed_at ?? $c->created_at))->format('d/m/Y')) ?>
                            </td>
                            <td class="small"><?= esc($c->account_name ?? ('#' . $c->account_id)) ?></td>
                            <td class="small">
                                <a href="<?= site_url('admin/leads/' . $c->lead_id) ?>">#<?= (int) $c->lead_id ?></a>
                            </td>
                            <td><span class="badge bg-light text-dark"><?= esc((string) $c->tipo_negocio) ?></span></td>
                            <td class="small text-muted"><?= esc((string) $c->origem) ?></td>
                            <td class="text-end fw-bold"><?= $brl($c->commission_value) ?></td>
                            <td><span class="badge bg-<?= $c->statusBadge() ?>"><?= esc($c->statusLabel()) ?></span></td>
                            <td class="text-end">
                                <?php if ($c->status === \App\Models\LeadChargeModel::STATUS_DISPUTED): ?>
                                    <span class="small text-muted d-block mb-1"><?= esc((string) $c->dispute_reason) ?></span>
                                    <button type="button" class="btn btn-link btn-sm text-success p-0 me-2 btn-disputa"
                                            data-id="<?= (int) $c->id ?>" data-procedente="1">procedente</button>
                                    <button type="button" class="btn btn-link btn-sm text-danger p-0 btn-disputa"
                                            data-id="<?= (int) $c->id ?>" data-procedente="0">improcedente</button>
                                <?php elseif ($c->isEditable()): ?>
                                    <button type="button" class="btn btn-link btn-sm text-danger p-0 btn-cancelar"
                                            data-id="<?= (int) $c->id ?>">cancelar</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $pager->links() ?>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(function () {
    function selecionadas() {
        return $('.chk-comissao:checked').map(function () { return this.value; }).get();
    }

    function atualizarBotao() {
        $('#btnAprovar').prop('disabled', selecionadas().length === 0);
    }

    $('#chkTodas').on('change', function () {
        $('.chk-comissao').prop('checked', this.checked);
        atualizarBotao();
    });

    $(document).on('change', '.chk-comissao', atualizarBotao);

    $('#btnAprovar').on('click', function () {
        const ids = selecionadas();

        Swal.fire({
            icon: 'question',
            title: `Aprovar ${ids.length} cobrança(s)?`,
            text: 'Depois de aprovadas, elas entram no próximo faturamento.',
            showCancelButton: true,
            confirmButtonText: 'Aprovar',
            cancelButtonText: 'Cancelar',
        }).then(function (res) {
            if (!res.isConfirmed) { return; }

            $.post('<?= site_url('admin/cobrancas/aprovar') ?>', { ids: ids })
                .done(function (r) {
                    Swal.fire(r.success ? 'Pronto' : 'Erro', r.message, r.success ? 'success' : 'error')
                        .then(() => { if (r.success) location.reload(); });
                })
                .fail(() => Swal.fire('Erro', 'Falha ao aprovar.', 'error'));
        });
    });

    $(document).on('click', '.btn-cancelar', function () {
        const id = $(this).data('id');

        Swal.fire({
            icon: 'warning',
            title: 'Cancelar esta cobrança?',
            input: 'text',
            inputLabel: 'Motivo (opcional)',
            showCancelButton: true,
            confirmButtonText: 'Cancelar cobrança',
            cancelButtonText: 'Voltar',
        }).then(function (res) {
            if (!res.isConfirmed) { return; }

            $.post('<?= site_url('admin/cobrancas') ?>/' + id + '/cancelar', { reason: res.value || '' })
                .done(function (r) {
                    Swal.fire(r.success ? 'Pronto' : 'Erro', r.message, r.success ? 'success' : 'error')
                        .then(() => { if (r.success) location.reload(); });
                })
                .fail(() => Swal.fire('Erro', 'Falha ao cancelar.', 'error'));
        });
    });

    $(document).on('click', '.btn-disputa', function () {
        const id = $(this).data('id');
        const procedente = $(this).data('procedente');
        const titulo = procedente ? 'Marcar disputa como procedente?' : 'Marcar disputa como improcedente?';
        const texto = procedente
            ? 'A cobrança vira isentada — o tenant não paga.'
            : 'A cobrança volta a valer e entra no próximo fechamento.';

        Swal.fire({
            icon: 'question',
            title: titulo,
            text: texto,
            input: 'text',
            inputLabel: 'Observação (opcional)',
            showCancelButton: true,
            confirmButtonText: 'Confirmar',
            cancelButtonText: 'Voltar',
        }).then(function (res) {
            if (!res.isConfirmed) { return; }

            $.post('<?= site_url('admin/cobrancas') ?>/' + id + '/resolver-disputa', { procedente: procedente, notes: res.value || '' })
                .done(function (r) {
                    Swal.fire(r.success ? 'Pronto' : 'Erro', r.message, r.success ? 'success' : 'error')
                        .then(() => { if (r.success) location.reload(); });
                })
                .fail(() => Swal.fire('Erro', 'Falha ao resolver disputa.', 'error'));
        });
    });
});
</script>
<?= $this->endSection() ?>
