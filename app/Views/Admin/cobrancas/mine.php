<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Minhas cobranças<?= $this->endSection() ?>
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

<div class="panel-card p-4 mb-4">
    <h6 class="fw-bold mb-2"><i class="fa-solid fa-circle-info text-primary me-1"></i> Como funciona</h6>
    <p class="text-muted small mb-2">
        Cada lead recebido nos seus imóveis gera uma cobrança. Você tem
        <strong><?= \App\Services\LeadChargeService::CONTEST_WINDOW_DIAS ?> dias</strong> pra contestar antes de ela
        ser aprovada automaticamente; só depois de aprovada ela entra na fatura do fechamento do mês.
    </p>
    <?php if ($regrasPlataforma !== []): ?>
        <?php
            $rotulosNegocio = ['VENDA' => 'Venda', 'ALUGUEL' => 'Aluguel', 'TEMPORADA' => 'Temporada'];
            $trechos        = [];
            foreach ($regrasPlataforma as $tipo => $regra) {
                $trechos[] = ($rotulosNegocio[$tipo] ?? $tipo) . ' R$ ' . number_format((float) $regra->value, 2, ',', '.');
            }
        ?>
        <p class="text-muted small mb-0">Preço vigente: <?= esc(implode(' · ', $trechos)) ?>.</p>
    <?php endif; ?>
</div>

<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label class="form-label small text-muted mb-1">Período</label>
        <select name="periodo" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach (($periodoOpcoes ?? []) as $valor => $rotulo): ?>
                <option value="<?= esc(substr($valor, 0, 7)) ?>" <?= $valor === $periodo ? 'selected' : '' ?>><?= esc($rotulo) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="kpi">
            <div class="text-muted small">Período</div>
            <div class="valor"><?= esc(\CodeIgniter\I18n\Time::parse($periodo)->format('M/Y')) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="kpi">
            <div class="text-muted small">Projetado no período</div>
            <div class="valor"><?= $brl($projetado) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="kpi">
            <div class="text-muted small">Crédito disponível</div>
            <div class="valor"><?= $brl($creditoAtual) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="kpi">
            <div class="text-muted small">A pagar (líquido do crédito)</div>
            <div class="valor"><?= $brl(max(0, $projetado - $creditoAtual)) ?></div>
        </div>
    </div>
</div>

<?php if ($totals !== []): ?>
    <div class="row g-3 mb-4">
        <?php foreach ($totals as $status => $t): ?>
            <div class="col-6 col-lg-3">
                <div class="kpi">
                    <div class="text-muted small"><?= esc(\App\Entities\LeadCharge::labelFor($status)) ?></div>
                    <div class="valor"><?= $brl($t['total']) ?></div>
                    <div class="text-muted small"><?= (int) $t['count'] ?> lead(s)</div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="panel-card p-4">
    <?php if ($commissions === []): ?>
        <p class="text-muted mb-0">
            Nenhuma cobrança gerada até agora.
        </p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Em</th>
                        <th>Lead</th>
                        <th>Negócio</th>
                        <th class="text-end">Valor</th>
                        <th>Situação</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($commissions as $c): ?>
                        <tr>
                            <td class="text-nowrap">
                                <?= esc(\CodeIgniter\I18n\Time::parse((string) ($c->closed_at ?? $c->created_at))->format('d/m/Y'))
                                    ?>
                            </td>
                            <td class="small">
                                <a href="<?= site_url('admin/leads/' . $c->lead_id) ?>">#<?= (int) $c->lead_id ?></a>
                            </td>
                            <td><span class="badge bg-light text-dark"><?= esc((string) $c->tipo_negocio) ?></span></td>
                            <td class="text-end fw-bold"><?= $brl($c->commission_value) ?></td>
                            <td><span class="badge bg-<?= $c->statusBadge() ?>"><?= esc($c->statusLabel()) ?></span></td>
                            <td class="text-end">
                                <?php if ($c->isContestable()): ?>
                                    <button type="button" class="btn btn-link btn-sm text-danger p-0 btn-contestar"
                                            data-id="<?= (int) $c->id ?>">contestar</button>
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
$(document).on('click', '.btn-contestar', function () {
    const id = $(this).data('id');

    Swal.fire({
        icon: 'question',
        title: 'Contestar esta cobrança?',
        input: 'text',
        inputLabel: 'Motivo',
        inputPlaceholder: 'ex.: lead não é meu, número errado, etc.',
        showCancelButton: true,
        confirmButtonText: 'Enviar contestação',
        cancelButtonText: 'Voltar',
        inputValidator: (value) => !value ? 'Informe o motivo.' : undefined,
    }).then(function (res) {
        if (!res.isConfirmed) { return; }

        $.post('<?= site_url('admin/minhas-cobrancas') ?>/' + id + '/contestar', { reason: res.value })
            .done(function (r) {
                Swal.fire(r.success ? 'Pronto' : 'Erro', r.message, r.success ? 'success' : 'error')
                    .then(() => { if (r.success) location.reload(); });
            })
            .fail(() => Swal.fire('Erro', 'Falha ao contestar.', 'error'));
    });
});
</script>
<?= $this->endSection() ?>
