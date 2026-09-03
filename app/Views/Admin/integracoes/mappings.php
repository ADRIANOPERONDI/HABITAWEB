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

<div class="form-check form-switch mb-3">
    <input class="form-check-input" type="checkbox" id="filtroNaoRevisados">
    <label class="form-check-label" for="filtroNaoRevisados">Mostrar só o que falta revisar</label>
</div>

<form method="post" action="<?= site_url('admin/integracoes/' . $provider->code . '/mapeamentos') ?>" id="formMapeamentos">
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
                            <tr class="<?= $map->is_confirmed ? '' : 'row-suggestion' ?>" data-confirmed="<?= $map->is_confirmed ? 1 : 0 ?>">
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
                            <tr class="<?= $map->is_confirmed ? '' : 'row-suggestion' ?>" data-confirmed="<?= $map->is_confirmed ? 1 : 0 ?>">
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

    <div class="d-flex gap-2 flex-wrap">
        <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-check me-1"></i> Salvar e confirmar mapeamentos
        </button>
        <button type="submit" name="confirm_all_suggestions" value="1" class="btn btn-outline-success"
                title="Confirma só as linhas que já têm um destino sugerido — o que ficou sem destino continua pendente">
            <i class="fa-solid fa-check-double me-1"></i> Confirmar todas as sugestões
        </button>
        <a href="<?= site_url('admin/integracoes/' . $provider->code) ?>" class="btn btn-outline-secondary">Cancelar</a>
    </div>
</form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
// Rastreia edição não salva: recarregar a página depois de "Redescobrir"
// descartaria qualquer escolha que o tenant já tenha feito nos <select> e
// ainda não submeteu.
let formularioSujo = false;
$('#formMapeamentos select').on('change', function () { formularioSujo = true; });

$('#filtroNaoRevisados').on('change', function () {
    const somenteNaoRevisados = this.checked;

    $('#formMapeamentos tr[data-confirmed]').each(function () {
        const confirmado = $(this).data('confirmed') === 1;
        $(this).toggle(!somenteNaoRevisados || !confirmado);
    });
});

$('#btnRedescobrir').on('click', function () {
    Swal.fire({ title: 'Consultando a origem...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    $.post('<?= site_url('admin/integracoes/' . $provider->code . '/redescobrir') ?>')
        .done(function (r) {
            if (!r.success) {
                Swal.fire({ icon: 'error', title: 'Não deu', text: r.message });
                return;
            }

            if (!formularioSujo) {
                Swal.fire({ icon: 'success', title: 'Pronto', text: r.message }).then(() => location.reload());
                return;
            }

            // Tem edição não salva nesta tela: recarregar agora a descartaria
            // sem aviso. Deixa a decisão explícita com o tenant.
            Swal.fire({
                icon: 'question',
                title: 'Pronto — mas você tem alterações não salvas aqui',
                text: r.message + ' Recarregar a página agora descarta o que você já marcou nos mapeamentos e ainda não salvou. Recarregar mesmo assim?',
                showCancelButton: true,
                confirmButtonText: 'Recarregar',
                cancelButtonText: 'Manter minhas alterações',
            }).then(function (res) {
                if (res.isConfirmed) { location.reload(); }
            });
        })
        .fail(function () {
            Swal.fire({ icon: 'error', title: 'Erro', text: 'Não foi possível consultar a origem.' });
        });
});
</script>
<?= $this->endSection() ?>
