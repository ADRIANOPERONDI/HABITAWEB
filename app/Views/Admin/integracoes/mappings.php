<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Mapeamentos<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Mapeamentos — <?= esc($provider->name) ?><?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .panel-card { border: 1px solid #f0f0f0; border-radius: 16px; background: #fff; }
    .row-suggestion { background: #fffbeb; }
    .external-label { font-weight: 500; }
    .external-id { font-size: .75rem; color: #9ca3af; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="mb-3">
    <a href="<?= site_url('admin/integracoes/' . $provider->code) ?>" class="text-decoration-none text-muted small">
        <i class="fa-solid fa-arrow-left me-1"></i> Voltar para a integração
    </a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-1"></i>
    Cada imobiliária cria os próprios códigos de categoria e de característica no
    <?= esc($provider->name) ?>, então este de/para é só seu. As linhas
    <span class="badge bg-warning text-dark">em amarelo</span> são sugestões automáticas
    que ainda não foram revisadas — confira e salve.
</div>

<form method="post" action="<?= site_url('admin/integracoes/' . $provider->code . '/mapeamentos') ?>">
    <?= csrf_field() ?>

    <div class="panel-card p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Tipos de imóvel</h5>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnRedescobrir">
                <i class="fa-solid fa-magnifying-glass me-1"></i> Buscar novidades na origem
            </button>
        </div>

        <?php if ($categories === []): ?>
            <p class="text-muted mb-0">
                Nada aqui ainda. Teste a conexão na tela anterior para trazer as categorias.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 45%">No <?= esc($provider->name) ?></th>
                            <th>Vira, no Habitaweb</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $map): ?>
                            <tr class="<?= $map->is_confirmed ? '' : 'row-suggestion' ?>">
                                <td>
                                    <div class="external-label"><?= esc($map->external_label ?: $map->external_id) ?></div>
                                    <div class="external-id">código <?= esc($map->external_id) ?></div>
                                </td>
                                <td>
                                    <select class="form-select form-select-sm"
                                            name="category[<?= esc($map->external_id, 'attr') ?>][target]">
                                        <option value="">— Não importar —</option>
                                        <?php foreach ($propertyTypes as $value => $label): ?>
                                            <option value="<?= esc($value, 'attr') ?>"
                                                <?= $map->target_value === $value ? 'selected' : '' ?>>
                                                <?= esc($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="panel-card p-4 mb-4">
        <h5 class="mb-3">Características</h5>
        <p class="text-muted small">
            O que não for mapeado não se perde: vai para o final da descrição do imóvel.
        </p>

        <?php if ($characteristics === []): ?>
            <p class="text-muted mb-0">Nenhuma característica encontrada ainda.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 45%">No <?= esc($provider->name) ?></th>
                            <th>Vira, no Habitaweb</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($characteristics as $map): ?>
                            <tr class="<?= $map->is_confirmed ? '' : 'row-suggestion' ?>">
                                <td>
                                    <div class="external-label"><?= esc($map->external_label ?: $map->external_id) ?></div>
                                    <div class="external-id">código <?= esc($map->external_id) ?></div>
                                </td>
                                <td>
                                    <select class="form-select form-select-sm"
                                            name="characteristic[<?= esc($map->external_id, 'attr') ?>][target]">
                                        <option value="">— Manter só na descrição —</option>
                                        <?php foreach ($targetFields as $value => $label): ?>
                                            <option value="<?= esc($value, 'attr') ?>"
                                                <?= $map->target_field === $value ? 'selected' : '' ?>>
                                                <?= esc($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-check me-1"></i> Salvar e confirmar mapeamentos
        </button>
        <a href="<?= site_url('admin/integracoes/' . $provider->code) ?>" class="btn btn-outline-secondary">Cancelar</a>
    </div>
</form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$('#btnRedescobrir').on('click', function () {
    Swal.fire({ title: 'Consultando a origem...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    $.post('<?= site_url('admin/integracoes/' . $provider->code . '/redescobrir') ?>')
        .done(function (r) {
            Swal.fire({
                icon: r.success ? 'success' : 'error',
                title: r.success ? 'Pronto' : 'Não deu',
                text: r.message,
            }).then(() => { if (r.success) location.reload(); });
        })
        .fail(function () {
            Swal.fire({ icon: 'error', title: 'Erro', text: 'Não foi possível consultar a origem.' });
        });
});
</script>
<?= $this->endSection() ?>
