<?= $this->extend('Layouts/public') ?>

<?= $this->section('title') ?><?= lang('App.activation_title') ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container py-5 mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card border-0 shadow-lg rounded-4 text-center p-4">
                <div class="card-body">
                    <div class="mb-4">
                        <div class="icon-square bg-primary-soft text-primary rounded-circle p-4 d-inline-flex mb-3">
                            <i class="fas fa-envelope-open-text fa-3x"></i>
                        </div>
                        <h2 class="fw-bold"><?= lang('App.activation_title') ?></h2>
                        <p class="text-muted"><?= lang('App.activation_subtitle') ?></p>
                    </div>

                    <?php if (session('error')) : ?>
                        <div class="alert alert-danger rounded-3"><?= session('error') ?></div>
                    <?php endif ?>

                    <form action="<?= site_url('auth/a/verify') ?>" method="post">
                        <?= csrf_field() ?>

                        <div class="mb-4">
                            <label class="form-label fw-bold small text-muted text-uppercase mb-3"><?= lang('App.activation_code_label') ?></label>
                            <div class="d-flex justify-content-center gap-2 mb-2">
                                <input type="text" name="token" class="form-control form-control-lg text-center fw-bold fs-2 bg-light border-0 rounded-4" 
                                       placeholder="000000" maxlength="6" pattern="[0-9]{6}" required autofocus style="letter-spacing: 15px; padding-left: 25px;">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold fs-5 shadow-sm mb-3">
                            <?= lang('App.activation_btn_verify') ?> <i class="fas fa-check-circle ms-2"></i>
                        </button>
                    </form>

                    <hr class="my-4 opacity-50">

                    <p class="text-muted small mb-3"><?= lang('App.activation_resend_txt') ?></p>
                    <a href="<?= site_url('auth/a/resend') ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-4">
                        <i class="fas fa-redo me-2"></i> <?= lang('App.activation_btn_resend') ?>
                    </a>
                </div>
            </div>
            
            <div class="text-center mt-4">
                <a href="<?= site_url('admin/logout') ?>" class="text-muted small text-decoration-none">
                    <i class="fas fa-sign-out-alt me-1"></i> <?= lang('App.activation_logout') ?>
                </a>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
