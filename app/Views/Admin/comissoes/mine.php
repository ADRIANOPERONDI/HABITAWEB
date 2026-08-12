<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Minhas comissões<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Comissões por negócio fechado<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .panel-card { border: 1px solid #f0f0f0; border-radius: 16px; background: #fff; }
    .kpi { border: 1px solid #f0f0f0; border-radius: 12px; padding: 14px 16px; background: #fff; }
    .kpi .valor { font-size: 1.25rem; font-weight: 700; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php $brl = static fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.'); ?>

<p class="text-muted">
    Negócios fechados a partir de leads recebidos em imóveis trazidos por integração.
    Os valores só entram em cobrança depois de conferidos.
</p>

<?php if ($totals !== []): ?>
    <div class="row g-3 mb-4">
        <?php foreach ($totals as $status => $t): ?>
            <div class="col-6 col-lg-3">
                <div class="kpi">
                    <div class="text-muted small"><?= esc($status) ?></div>
                    <div class="valor"><?= $brl($t['total']) ?></div>
                    <div class="text-muted small"><?= (int) $t['count'] ?> negócio(s)</div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="panel-card p-4">
    <?php if ($commissions === []): ?>
        <p class="text-muted mb-0">
            Nenhuma comissão apurada até agora.
        </p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Fechado em</th>
                        <th>Lead</th>
                        <th>Negócio</th>
                        <th class="text-end">Valor do negócio</th>
                        <th class="text-end">Comissão</th>
                        <th>Situação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($commissions as $c): ?>
                        <tr>
                            <td class="text-nowrap">
                                <?= $c->closed_at
                                    ? esc(\CodeIgniter\I18n\Time::parse((string) $c->closed_at)->format('d/m/Y'))
                                    : '—' ?>
                            </td>
                            <td class="small">
                                <a href="<?= site_url('admin/leads/' . $c->lead_id) ?>">#<?= (int) $c->lead_id ?></a>
                            </td>
                            <td><span class="badge bg-light text-dark"><?= esc((string) $c->tipo_negocio) ?></span></td>
                            <td class="text-end"><?= $brl($c->base_value) ?></td>
                            <td class="text-end fw-bold"><?= $brl($c->commission_value) ?></td>
                            <td><span class="badge bg-<?= $c->statusBadge() ?>"><?= esc($c->statusLabel()) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $pager->links() ?>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
