<?= $this->extend('Auth/layout') ?>

<?= $this->section('title') ?><?= lang('Auth.useMagicLink') ?> <?= $this->endSection() ?>

<?= $this->section('main') ?>
<div class="container d-flex justify-content-center align-items-center min-vh-100">
    <div class="card shadow-lg border-0 rounded-4" style="width: 100%; max-width: 440px;">
        <div class="card-body p-5 text-center">
            <div class="icon-box-success bg-success text-white mb-4 mx-auto" style="width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                <i class="fa-solid fa-envelope-circle-check"></i>
            </div>
            
            <h3 class="fw-bold mb-3" style="font-family: 'Outfit', sans-serif;"><?= lang('App.magic_link_check_email_title') ?></h3>
            <p class="text-muted mb-4">
                <?= lang('App.magic_link_check_email_desc') ?>
            </p>

            <div class="d-grid mb-4">
                <a href="<?= base_url('admin/login') ?>" class="btn btn-primary btn-lg rounded-3 fw-bold" style="background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%); border: none;">
                    <?= lang('App.magic_link_back_login') ?>
                </a>
            </div>

            <p class="small text-muted mb-0">
                O link expira em 1 hora.
            </p>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
