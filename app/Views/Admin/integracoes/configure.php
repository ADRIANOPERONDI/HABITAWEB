<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?><?= esc($provider->name) ?><?= $this->endSection() ?>
<?= $this->section('page_title') ?>Integração — <?= esc($provider->name) ?><?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .panel-card { border: 1px solid #f0f0f0; border-radius: 16px; background: #fff; }
    .lock-badge { font-size: .7rem; background: #ecfdf5; color: #047857; padding: 2px 8px; border-radius: 6px; }
    .field-help { font-size: .8rem; color: #6b7280; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php
    $statusMap = [
        \App\Models\AccountIntegrationModel::STATUS_CONNECTED => ['success', 'Conectado'],
        \App\Models\AccountIntegrationModel::STATUS_ERROR     => ['danger', 'Erro'],
        \App\Models\AccountIntegrationModel::STATUS_PAUSED    => ['warning', 'Pausado'],
    ];
    [$badgeClass, $badgeLabel] = $statusMap[$integration->status] ?? ['secondary', 'Aguardando teste'];
?>

<div class="mb-3">
    <a href="<?= site_url('admin/integracoes') ?>" class="text-decoration-none text-muted small">
        <i class="fa-solid fa-arrow-left me-1"></i> Voltar para integrações
    </a>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="panel-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="mb-0">Credenciais</h5>
                <span class="badge bg-<?= $badgeClass ?>"><?= esc($badgeLabel) ?></span>
            </div>

            <?php if ($integration->last_test_message): ?>
                <div class="alert alert-<?= $integration->isConnected() ? 'success' : 'danger' ?> py-2 px-3 small">
                    <?= esc($integration->last_test_message) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= site_url('admin/integracoes/' . $provider->code) ?>">
                <?= csrf_field() ?>

                <?php foreach ($provider->getSchemaFields() as $field): ?>
                    <?php
                        $key       = $field['key'];
                        $sensitive = ! empty($field['is_sensitive']);
                        $current   = $credentials[$key] ?? '';
                    ?>
                    <div class="mb-3">
                        <label class="form-label" for="cfg_<?= esc($key, 'attr') ?>">
                            <?= esc($field['label'] ?? $key) ?>
                            <?php if (! empty($field['required'])): ?><span class="text-danger">*</span><?php endif; ?>
                            <?php if ($sensitive): ?>
                                <span class="lock-badge ms-1"><i class="fa-solid fa-lock"></i> Seguro</span>
                            <?php endif; ?>
                        </label>

                        <input
                            type="<?= $sensitive ? 'password' : 'text' ?>"
                            class="form-control"
                            id="cfg_<?= esc($key, 'attr') ?>"
                            name="config[<?= esc($key, 'attr') ?>]"
                            autocomplete="off"
                            <?php if ($sensitive): ?>
                                <?php /* Nunca devolvemos o segredo ao navegador: só o que já existe, mascarado. */ ?>
                                placeholder="<?= $current !== '' ? esc($current, 'attr') . ' (deixe em branco para manter)' : 'Cole o token aqui' ?>"
                            <?php else: ?>
                                value="<?= esc($current, 'attr') ?>"
                                placeholder="<?= esc($field['placeholder'] ?? '', 'attr') ?>"
                            <?php endif; ?>
                        >

                        <?php if (! empty($field['help'])): ?>
                            <div class="field-help mt-1"><?= esc($field['help']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <hr class="my-4">
                <h6 class="mb-3">Sincronização</h6>

                <div class="mb-3">
                    <label class="form-label d-block">O que importar</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="fin_2" name="settings[finalidades][]" value="2"
                            <?= in_array(2, $settings['finalidades'], true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="fin_2">Imóveis à venda</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="fin_1" name="settings[finalidades][]" value="1"
                            <?= in_array(1, $settings['finalidades'], true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="fin_1">Imóveis para locação</label>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="initial_status">Como o imóvel novo entra</label>
                        <select class="form-select" id="initial_status" name="settings[initial_status]">
                            <option value="DRAFT" <?= $settings['initial_status'] === 'DRAFT' ? 'selected' : '' ?>>
                                Rascunho (você revisa antes de publicar)
                            </option>
                            <option value="ACTIVE" <?= $settings['initial_status'] === 'ACTIVE' ? 'selected' : '' ?>>
                                Publicado direto no portal
                            </option>
                        </select>
                        <div class="field-help mt-1">
                            Comece por rascunho até conferir os mapeamentos.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="max_images">Máximo de fotos por imóvel</label>
                        <input type="number" class="form-control" id="max_images" name="settings[max_images]"
                               min="1" max="50" value="<?= (int) $settings['max_images'] ?>">
                    </div>
                </div>

                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" id="import_images" name="settings[import_images]" value="1"
                        <?= $settings['import_images'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="import_images">Importar as fotos dos imóveis</label>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Salvar configurações
                </button>
            </form>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="panel-card p-4 mb-4">
            <h6 class="mb-3">Ações</h6>

            <button type="button" class="btn btn-outline-primary w-100 mb-2" id="btnTestar">
                <i class="fa-solid fa-plug-circle-check me-1"></i> Testar conexão
            </button>

            <button type="button" class="btn btn-outline-success w-100 mb-2" id="btnSincronizar"
                <?= $integration->isConnected() ? '' : 'disabled' ?>>
                <i class="fa-solid fa-rotate me-1"></i> Sincronizar agora
            </button>

            <button type="button" class="btn btn-outline-secondary w-100 mb-2" id="btnToggle"
                <?= $integration->isConnected() || $integration->is_active ? '' : 'disabled' ?>>
                <i class="fa-solid fa-clock me-1"></i>
                <?= $integration->is_active ? 'Pausar sincronização automática' : 'Ativar sincronização automática' ?>
            </button>

            <hr>

            <a href="<?= site_url('admin/integracoes/' . $provider->code . '/mapeamentos') ?>"
               class="btn btn-outline-dark w-100 mb-2">
                <i class="fa-solid fa-right-left me-1"></i> Mapeamentos
                <?php if ($unconfirmed > 0): ?>
                    <span class="badge bg-warning text-dark ms-1"><?= (int) $unconfirmed ?></span>
                <?php endif; ?>
            </a>

            <a href="<?= site_url('admin/integracoes/' . $provider->code . '/execucoes') ?>"
               class="btn btn-outline-dark w-100">
                <i class="fa-solid fa-list-check me-1"></i> Histórico de sincronizações
            </a>
        </div>

        <div class="panel-card p-4">
            <h6 class="mb-3">Situação</h6>
            <dl class="row mb-0 small">
                <dt class="col-6 text-muted fw-normal">Imóveis sincronizados</dt>
                <dd class="col-6 text-end"><?= (int) $synced ?></dd>

                <dt class="col-6 text-muted fw-normal">Sync automático</dt>
                <dd class="col-6 text-end">
                    <?= $integration->is_active ? '<span class="text-success">Ativo</span>' : '<span class="text-muted">Desligado</span>' ?>
                </dd>

                <dt class="col-6 text-muted fw-normal">Última sincronização</dt>
                <dd class="col-6 text-end">
                    <?= $integration->last_sync_at
                        ? esc(\CodeIgniter\I18n\Time::parse((string) $integration->last_sync_at)->humanize())
                        : '—' ?>
                </dd>

                <?php if ($lastRun !== null): ?>
                    <dt class="col-6 text-muted fw-normal">Último resultado</dt>
                    <dd class="col-6 text-end">
                        <span class="badge bg-<?= $lastRun->statusBadge() ?>"><?= esc($lastRun->status) ?></span>
                    </dd>
                <?php endif; ?>
            </dl>
        </div>

        <div class="mt-3 text-center">
            <form method="post" action="<?= site_url('admin/integracoes/' . $provider->code . '/desconectar') ?>"
                  onsubmit="return confirm('Apagar as credenciais desta integração? Os imóveis já importados continuam no seu catálogo.');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-link btn-sm text-danger text-decoration-none">
                    Desconectar integração
                </button>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    const base = '<?= site_url('admin/integracoes/' . $provider->code) ?>';

    // O CSRF vai automático pelo $.ajaxSetup do Layouts/master.
    function executar(url, titulo) {
        Swal.fire({ title: titulo, allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        return $.post(url)
            .done(function (r) {
                Swal.fire({
                    icon: r.success ? 'success' : 'error',
                    title: r.success ? 'Tudo certo' : 'Não deu',
                    text: r.message,
                }).then(() => { if (r.success) location.reload(); });
            })
            .fail(function () {
                Swal.fire({ icon: 'error', title: 'Erro', text: 'Não foi possível concluir. Tente novamente.' });
            });
    }

    $('#btnTestar').on('click', function () {
        executar(base + '/testar', 'Falando com o <?= esc($provider->name, 'js') ?>...');
    });

    $('#btnSincronizar').on('click', function () {
        Swal.fire({
            icon: 'question',
            title: 'Sincronizar agora?',
            text: 'Pode levar alguns minutos em catálogos grandes.',
            showCancelButton: true,
            confirmButtonText: 'Sincronizar',
            cancelButtonText: 'Cancelar',
        }).then(function (res) {
            if (res.isConfirmed) {
                executar(base + '/sincronizar', 'Sincronizando o catálogo...');
            }
        });
    });

    $('#btnToggle').on('click', function () {
        executar(base + '/toggle', 'Atualizando...');
    });
})();
</script>
<?= $this->endSection() ?>
