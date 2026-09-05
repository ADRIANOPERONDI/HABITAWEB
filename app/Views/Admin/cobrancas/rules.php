<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Regras de cobrança<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Regras de cobrança<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .panel-card { border: 1px solid #f0f0f0; border-radius: 16px; background: #fff; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="mb-3">
    <a href="<?= site_url('admin/cobrancas') ?>" class="text-decoration-none text-muted small">
        <i class="fa-solid fa-arrow-left me-1"></i> Voltar para as cobranças
    </a>
</div>

<div class="alert alert-info">
    <i class="fa-solid fa-circle-info me-1"></i>
    A regra mais específica vence: primeiro a da conta para aquele tipo de negócio,
    depois a da conta para qualquer tipo, e por último a regra padrão da plataforma
    (a que fica com a conta em branco).
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="panel-card p-4">
            <h5 class="mb-3">Regras cadastradas</h5>

            <?php if ($rules === []): ?>
                <p class="text-muted mb-0">
                    Nenhuma regra ainda — sem regra, nenhuma cobrança é gerada.
                    Comece cadastrando a padrão da plataforma ao lado.
                </p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Conta</th>
                                <th>Negócio</th>
                                <th>Cobrança</th>
                                <th>Vigência</th>
                                <th>Situação</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rules as $rule): ?>
                                <tr>
                                    <td class="small">
                                        <?php if ($rule->account_id === null): ?>
                                            <span class="badge bg-dark">Padrão da plataforma</span>
                                        <?php else: ?>
                                            <?php
                                                $conta = null;
                                                foreach ($accounts as $a) {
                                                    if ((int) $a->id === (int) $rule->account_id) { $conta = $a; break; }
                                                }
                                            ?>
                                            <?= esc($conta->nome ?? ('#' . $rule->account_id)) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark">
                                            <?= esc($rule->tipo_negocio ?: 'Qualquer') ?>
                                        </span>
                                    </td>
                                    <td class="fw-bold"><?= esc($rule->describe()) ?></td>
                                    <td class="small text-muted">
                                        <?= esc($rule->valid_from ? \CodeIgniter\I18n\Time::parse((string) $rule->valid_from)->format('d/m/Y') : 'sempre') ?>
                                        —
                                        <?= esc($rule->valid_to ? \CodeIgniter\I18n\Time::parse((string) $rule->valid_to)->format('d/m/Y') : 'sem fim') ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $rule->is_active ? 'success' : 'secondary' ?>">
                                            <?= $rule->is_active ? 'Ativa' : 'Inativa' ?>
                                        </span>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn btn-link btn-sm p-0 me-2 btn-editar"
                                                data-id="<?= (int) $rule->id ?>"
                                                data-account-id="<?= (int) $rule->account_id ?>"
                                                data-tipo-negocio="<?= esc((string) $rule->tipo_negocio, 'attr') ?>"
                                                data-model="<?= esc((string) $rule->model, 'attr') ?>"
                                                data-value="<?= esc((string) $rule->value, 'attr') ?>"
                                                data-min-value="<?= esc((string) $rule->min_value, 'attr') ?>"
                                                data-max-value="<?= esc((string) $rule->max_value, 'attr') ?>"
                                                data-valid-from="<?= esc((string) $rule->valid_from, 'attr') ?>"
                                                data-valid-to="<?= esc((string) $rule->valid_to, 'attr') ?>"
                                                data-notes="<?= esc((string) $rule->notes, 'attr') ?>"
                                                data-is-active="<?= $rule->is_active ? '1' : '0' ?>">editar</button>
                                        <button type="button" class="btn btn-link btn-sm text-danger p-0 btn-excluir"
                                                data-id="<?= (int) $rule->id ?>">excluir</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="panel-card p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0" id="formTitulo">Nova regra</h5>
                <button type="button" class="btn btn-link btn-sm p-0 d-none" id="btnCancelarEdicao">cancelar edição</button>
            </div>

            <form method="post" action="<?= site_url('admin/cobrancas/regras') ?>" id="formRegra">
                <?= csrf_field() ?>
                <input type="hidden" name="id" id="rule_id" value="">

                <div class="mb-3">
                    <label class="form-label" for="account_id">Conta</label>
                    <select class="form-select" id="account_id" name="account_id">
                        <option value="">Padrão da plataforma (todas as contas)</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= (int) $a->id ?>"><?= esc($a->nome) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="tipo_negocio">Tipo de negócio</label>
                    <select class="form-select" id="tipo_negocio" name="tipo_negocio">
                        <option value="">Qualquer</option>
                        <option value="ALUGUEL">Aluguel</option>
                        <option value="VENDA">Venda</option>
                        <option value="TEMPORADA">Temporada</option>
                    </select>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="model">Modelo</label>
                        <select class="form-select" id="model" name="model">
                            <option value="FIXED" selected>Valor fixo</option>
                            <option value="PERCENT">Percentual</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="value">Valor</label>
                        <input type="text" class="form-control" id="value" name="value" placeholder="10" required>
                        <div class="form-text">Percentual do valor de fechamento (negócio fechado) ou reais fixos (lead recebido).</div>
                    </div>
                </div>

                <div class="row g-2 mb-3" id="minMaxWrapper">
                    <div class="col-6">
                        <label class="form-label" for="min_value">Mínimo (R$)</label>
                        <input type="text" class="form-control" id="min_value" name="min_value" placeholder="opcional">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="max_value">Máximo (R$)</label>
                        <input type="text" class="form-control" id="max_value" name="max_value" placeholder="opcional">
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="valid_from">Vigência — de</label>
                        <input type="date" class="form-control" id="valid_from" name="valid_from">
                        <div class="form-text">Vazio = vale desde sempre.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="valid_to">Vigência — até</label>
                        <input type="date" class="form-control" id="valid_to" name="valid_to">
                        <div class="form-text">Vazio = sem data de fim.</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="notes">Observação</label>
                    <input type="text" class="form-control" id="notes" name="notes" placeholder="ex.: acordo comercial 2026">
                </div>

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                    <label class="form-check-label" for="is_active">Regra ativa</label>
                </div>

                <button type="submit" class="btn btn-primary w-100" id="btnSalvarRegra">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Salvar regra
                </button>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
// Mínimo/máximo só faz sentido no percentual: no valor fixo, o valor já É o
// único número da regra — mostrar os dois campos ali só confundiria sobre o
// que de fato é usado em LeadChargeRule::calculate().
function alternarMinMax() {
    $('#minMaxWrapper').toggleClass('d-none', $('#model').val() !== 'PERCENT');
}
$('#model').on('change', alternarMinMax);
alternarMinMax();

function resetarFormulario() {
    $('#formRegra')[0].reset();
    $('#rule_id').val('');
    $('#formTitulo').text('Nova regra');
    $('#btnSalvarRegra').html('<i class="fa-solid fa-floppy-disk me-1"></i> Salvar regra');
    $('#btnCancelarEdicao').addClass('d-none');
    alternarMinMax();
}

$(document).on('click', '.btn-editar', function () {
    const d = $(this).data();

    $('#rule_id').val(d.id);
    $('#account_id').val(d.accountId > 0 ? d.accountId : '');
    $('#tipo_negocio').val(d.tipoNegocio || '');
    $('#model').val(d.model || 'FIXED');
    $('#value').val(d.value ?? '');
    $('#min_value').val(d.minValue ?? '');
    $('#max_value').val(d.maxValue ?? '');
    $('#valid_from').val(d.validFrom || '');
    $('#valid_to').val(d.validTo || '');
    $('#notes').val(d.notes || '');
    $('#is_active').prop('checked', String(d.isActive) === '1');

    alternarMinMax();

    $('#formTitulo').text('Editando regra #' + d.id);
    $('#btnSalvarRegra').html('<i class="fa-solid fa-floppy-disk me-1"></i> Atualizar regra');
    $('#btnCancelarEdicao').removeClass('d-none');

    $('html, body').animate({ scrollTop: $('#formRegra').offset().top - 80 }, 200);
});

$('#btnCancelarEdicao').on('click', resetarFormulario);

$(document).on('click', '.btn-excluir', function () {
    confirmAction(
        '<?= site_url('admin/cobrancas/regras') ?>/' + $(this).data('id'),
        'DELETE',
        'Excluir esta regra?',
        'As cobranças já geradas por ela continuam como estão.'
    );
});
</script>
<?= $this->endSection() ?>
