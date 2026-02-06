<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?><?= isset($plan) ? 'Editar' : 'Novo' ?> Plano<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Gestão de Planos<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 fw-bold"><?= isset($plan) ? 'Editar' : 'Criar' ?> Plano</h5>
            </div>
            <div class="card-body p-4">
                <form action="<?= isset($plan) ? site_url('admin/plans/' . $plan->id) : site_url('admin/plans') ?>" method="post">
                    <?= csrf_field() ?>
                    <?php if(isset($plan)): ?>
                        <input type="hidden" name="_method" value="PUT">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Nome do Plano</label>
                        <input type="text" name="nome" class="form-control" value="<?= old('nome', $plan->nome ?? '') ?>" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Preço Mensal (R$)</label>
                            <input type="text" name="preco_mensal" class="form-control double3" value="<?= number_format((float)old('preco_mensal', $plan->preco_mensal ?? 0), 2, ',', '.') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Preço Trimestral (R$)</label>
                            <input type="text" name="preco_trimestral" class="form-control double3" value="<?= number_format((float)old('preco_trimestral', $plan->preco_trimestral ?? 0), 2, ',', '.') ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                         <div class="col-md-6">
                            <label class="form-label">Preço Semestral (R$)</label>
                            <input type="text" name="preco_semestral" class="form-control double3" value="<?= number_format((float)old('preco_semestral', $plan->preco_semestral ?? 0), 2, ',', '.') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Preço Anual (R$)</label>
                            <input type="text" name="preco_anual" class="form-control double3" value="<?= number_format((float)old('preco_anual', $plan->preco_anual ?? 0), 2, ',', '.') ?>">
                        </div>
                    </div>
                
                    <hr>

                    <div class="mb-3">
                        <label class="form-label">Limite de Imóveis Ativos</label>
                        <input type="number" name="limite_imoveis_ativos" class="form-control" value="<?= old('limite_imoveis_ativos', $plan->limite_imoveis_ativos ?? '') ?>" placeholder="Deixe em branco para Ilimitado">
                        <div class="form-text">Deixe vazio para ilimitado ou defina um número exato.</div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Fotos por Imóvel</label>
                            <input type="number" name="limite_fotos_por_imovel" class="form-control" value="<?= old('limite_fotos_por_imovel', $plan->limite_fotos_por_imovel ?? '10') ?>" required>
                        </div>
                         <div class="col-md-6">
                            <label class="form-label">Destaques Mensais</label>
                            <input type="number" name="destaques_mensais" class="form-control" value="<?= old('destaques_mensais', $plan->destaques_mensais ?? '0') ?>" required>
                        </div>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="ativo" id="ativoSwitch" <?= (!isset($plan) || $plan->ativo) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ativoSwitch">Plano Ativo (Disponível para venda)</label>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= site_url('admin/plans') ?>" class="btn btn-light">Cancelar</a>
                        <button type="submit" class="btn btn-primary px-4">Salvar Plano</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
