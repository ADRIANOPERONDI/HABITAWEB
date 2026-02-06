<?= $this->extend('Layouts/master') ?>

<?= $this->section('page_title') ?><?= isset($package) ? 'Editar' : 'Novo' ?> Pacote<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="animate-fade-in">
    <div class="d-flex align-items-center mb-4">
        <a href="<?= site_url('admin/packages') ?>" class="btn btn-light rounded-circle shadow-sm me-3" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <h1 class="h3 mb-0 text-gray-800"><?= isset($package) ? 'Editar' : 'Novo' ?> Pacote</h1>
    </div>

    <?= view('App\Views\admin\partials\alerts') ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <form action="<?= isset($package) ? site_url('admin/packages/update/' . $package->id) : site_url('admin/packages/create') ?>" method="post">
                        <?= csrf_field() ?>

                        <div class="mb-4">
                            <label class="form-label fw-bold small text-uppercase text-muted">Nome do Pacote</label>
                            <input type="text" name="nome" class="form-control form-control-lg" required placeholder="Ex: Turbo Semanal" value="<?= old('nome', $package->nome ?? '') ?>">
                            <small class="text-muted">Nome exibido na tela de contratação.</small>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <label class="form-label fw-bold small text-uppercase text-muted">Chave Única (Código)</label>
                                <input type="text" name="chave" class="form-control" required placeholder="Ex: TURBO_SEMANA_7" value="<?= old('chave', $package->chave ?? '') ?>">
                                <small class="text-muted">Identificador interno (sem espaços, maiúsculo).</small>
                            </div>
                            <div class="col-md-6 mb-4">
                                <label class="form-label fw-bold small text-uppercase text-muted">Tipo de Promoção</label>
                                <select name="tipo_promocao" class="form-select" required>
                                    <option value="DESTAQUE" <?= (old('tipo_promocao', $package->tipo_promocao ?? '') == 'DESTAQUE') ? 'selected' : '' ?>>Destaque (Prata/Padrão)</option>
                                    <option value="SUPER_DESTAQUE" <?= (old('tipo_promocao', $package->tipo_promocao ?? '') == 'SUPER_DESTAQUE') ? 'selected' : '' ?>>Super Destaque (Ouro)</option>
                                    <option value="VITRINE" <?= (old('tipo_promocao', $package->tipo_promocao ?? '') == 'VITRINE') ? 'selected' : '' ?>>Vitrine (Diamante)</option>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <label class="form-label fw-bold small text-uppercase text-muted">Preço (R$)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">R$</span>
                                    <input type="text" name="preco" class="form-control money" required placeholder="0,00" value="<?= old('preco', isset($package->preco) ? number_format($package->preco, 2, ',', '.') : '') ?>">
                                </div>
                            </div>
                            <div class="col-md-6 mb-4">
                                <label class="form-label fw-bold small text-uppercase text-muted">Duração (Dias)</label>
                                <input type="number" name="duracao_dias" class="form-control" required min="1" value="<?= old('duracao_dias', $package->duracao_dias ?? 7) ?>">
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4 pt-4 border-top">
                            <a href="<?= site_url('admin/packages') ?>" class="btn btn-link text-decoration-none text-muted me-3">Cancelar</a>
                            <button type="submit" class="btn btn-primary px-5 rounded-pill fw-bold">
                                <i class="fa-solid fa-save me-2"></i> Salvar Pacote
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card bg-light border-0">
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="fa-solid fa-circle-info text-primary me-2"></i> Infos</h6>
                    <p class="small text-muted mb-2">
                        <strong>Chave:</strong> Use chaves únicas e descritivas. Elas são usadas internamente para identificar o pacote.
                    </p>
                    <p class="small text-muted mb-2">
                        <strong>Tipo:</strong> Define a força do impulsionamento e os ícones visuais (Prata/Ouro/Diamante).
                    </p>
                    <p class="small text-muted">
                        <strong>Preço:</strong> Valor cobrado via Asaas (Pix/Boleto/Cartão).
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
<script>
$(document).ready(function(){
    $('.money').mask('#.##0,00', {reverse: true});
});
</script>
<?= $this->endSection() ?>
