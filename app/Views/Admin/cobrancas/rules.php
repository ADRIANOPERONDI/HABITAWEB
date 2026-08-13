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
                                    <td>
                                        <span class="badge bg-<?= $rule->is_active ? 'success' : 'secondary' ?>">
                                            <?= $rule->is_active ? 'Ativa' : 'Inativa' ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
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
            <h5 class="mb-3">Nova regra</h5>

            <form method="post" action="<?= site_url('admin/cobrancas/regras') ?>">
                <?= csrf_field() ?>

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
                            <option value="PERCENT">Percentual</option>
                            <option value="FIXED">Valor fixo</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="value">Valor</label>
                        <input type="text" class="form-control" id="value" name="value" placeholder="10" required>
                        <div class="form-text">Percentual do valor de fechamento (negócio fechado) ou reais fixos (lead recebido).</div>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="min_value">Mínimo (R$)</label>
                        <input type="text" class="form-control" id="min_value" name="min_value" placeholder="opcional">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="max_value">Máximo (R$)</label>
                        <input type="text" class="form-control" id="max_value" name="max_value" placeholder="opcional">
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

                <button type="submit" class="btn btn-primary w-100">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Salvar regra
                </button>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
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
