<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Editar Usuário<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Editar Usuário<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-4 animate-fade-in">
            <div class="card-header bg-white border-bottom p-4">
                <h5 class="fw-bold mb-0">Informações do Usuário</h5>
                <p class="text-muted small mb-0">Atualize os dados de acesso do colaborador.</p>
            </div>
            <div class="card-body p-4">
                <form action="<?= site_url('admin/users/' . $user->id . '/update') ?>" method="post">
                    <?= csrf_field() ?>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase">Username</label>
                        <input type="text" name="username" class="form-control" value="<?= old('username', $user->username) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase">E-mail</label>
                        <input type="email" name="email" class="form-control" value="<?= old('email', $user->email) ?>" required>
                    </div>

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="active" id="activeSwitch" <?= $user->active ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold small text-uppercase" for="activeSwitch">Usuário Ativo</label>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center border-top pt-4">
                        <a href="<?= site_url('admin/users') ?>" class="btn btn-light rounded-pill px-4">Cancelar</a>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 shadow">
                            <i class="fa-solid fa-save me-2"></i> Salvar Alterações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
