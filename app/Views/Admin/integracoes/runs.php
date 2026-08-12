<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Sincronizações<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Sincronizações — <?= esc($provider->name) ?><?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .panel-card { border: 1px solid #f0f0f0; border-radius: 16px; background: #fff; }
    .err-cell { max-width: 320px; font-size: .8rem; color: #b91c1c; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="mb-3">
    <a href="<?= site_url('admin/integracoes/' . $provider->code) ?>" class="text-decoration-none text-muted small">
        <i class="fa-solid fa-arrow-left me-1"></i> Voltar para a integração
    </a>
</div>

<div class="panel-card p-4">
    <?php if ($runs === []): ?>
        <p class="text-muted mb-0">Nenhuma sincronização registrada ainda.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Quando</th>
                        <th>Origem</th>
                        <th>Status</th>
                        <th class="text-end">Novos</th>
                        <th class="text-end">Atualizados</th>
                        <th class="text-end">Sem mudança</th>
                        <th class="text-end">Pausados</th>
                        <th class="text-end">Fotos</th>
                        <th class="text-end">Duração</th>
                        <th>Observação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runs as $run): ?>
                        <tr>
                            <td class="text-nowrap">
                                <?= $run->started_at
                                    ? esc(\CodeIgniter\I18n\Time::parse((string) $run->started_at)->format('d/m/Y H:i'))
                                    : '—' ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark">
                                    <?= $run->trigger_type === 'manual' ? 'Manual' : 'Automático' ?>
                                </span>
                            </td>
                            <td><span class="badge bg-<?= $run->statusBadge() ?>"><?= esc($run->status) ?></span></td>
                            <td class="text-end"><?= (int) $run->created_count ?></td>
                            <td class="text-end"><?= (int) $run->updated_count ?></td>
                            <td class="text-end text-muted"><?= (int) $run->skipped_count ?></td>
                            <td class="text-end"><?= (int) $run->paused_count ?></td>
                            <td class="text-end"><?= (int) $run->images_count ?></td>
                            <td class="text-end text-muted">
                                <?php $d = $run->durationSeconds(); ?>
                                <?= $d === null ? '—' : ($d < 60 ? $d . 's' : intdiv($d, 60) . 'min') ?>
                            </td>
                            <td class="err-cell"><?= esc((string) $run->error_message) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
