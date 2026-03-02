<?= $this->extend('Auth/layout') ?>

<?= $this->section('title') ?><?= lang('Auth.useMagicLink') ?> <?= $this->endSection() ?>

<?= $this->section('main') ?>
<div class="container d-flex justify-content-center align-items-center min-vh-100">
    <div class="card shadow-lg border-0 rounded-4" style="width: 100%; max-width: 440px;">
        <div class="card-body p-5">
            <div class="text-center mb-4">
                <div class="icon-box-recovery bg-primary text-white mb-3 mx-auto" style="width: 60px; height: 60px; border-radius: 15px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                </div>
                <h3 class="fw-bold" style="font-family: 'Outfit', sans-serif;"><?= lang('App.magic_link_title') ?></h3>
                <p class="text-muted small"><?= lang('App.magic_link_subtitle') ?></p>
            </div>

            <?php if (session('error')) : ?>
                <div class="alert alert-danger shadow-sm border-0 rounded-3 mb-4">
                    <?= session('error') ?>
                </div>
            <?php endif ?>

            <form action="<?= url_to('magic-link') ?>" method="post">
                <?= csrf_field() ?>

                <!-- Email -->
                <div class="mb-4">
                    <label class="form-label small fw-bold text-muted text-uppercase"><?= lang('App.login_email_label') ?></label>
                    <input type="email" class="form-control" name="email" inputmode="email" autocomplete="email" placeholder="seu@email.com" value="<?= old('email', auth()->user()->email ?? null) ?>" required autofocus>
                </div>

                <div class="d-grid mb-4">
                    <button type="submit" class="btn btn-primary btn-lg rounded-3 fw-bold" style="background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%); border: none;">
                        <?= lang('App.magic_link_btn_send') ?> <i class="fa-solid fa-paper-plane ms-2"></i>
                    </button>
                </div>

                <div class="text-center">
                    <a href="<?= base_url('admin/login') ?>" class="text-muted small text-decoration-none">
                        <i class="fa-solid fa-arrow-left me-1"></i> <?= lang('App.magic_link_back_login') ?>
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .form-control {
        border-radius: 12px;
        padding: 14px 18px;
        background: #f8fafc;
        border: 2px solid #e2e8f0;
        transition: all 0.3s;
    }
    .form-control:focus {
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        border-color: #6366f1;
        background: #fff;
    }
</style>
<?= $this->endSection() ?>
