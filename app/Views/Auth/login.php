<?= $this->extend(config('Auth')->views['layout']) ?>

<?= $this->section('title') ?>Login<?= $this->endSection() ?>

<?= $this->section('main') ?>
<div class="container d-flex justify-content-center align-items-center min-vh-100">
    <div class="card shadow-lg border-0 rounded-4" style="width: 100%; max-width: 400px;">
        <div class="card-body p-5">
            <div class="text-center mb-4">
                <i class="fa-solid fa-map-location-dot text-primary fa-3x mb-2"></i>
                <h4 class="fw-bold">Portal<span class="text-primary">Imóveis</span></h4>
                <p class="text-muted small">Bem-vindo de volta!</p>
            </div>

            <?php if (session('error') !== null) : ?>
                <div class="alert alert-danger" role="alert"><?= session('error') ?></div>
            <?php elseif (session('errors') !== null) : ?>
                <div class="alert alert-danger" role="alert">
                    <?php if (is_array(session('errors'))) : ?>
                        <?php foreach (session('errors') as $error) : ?>
                            <?= $error ?>
                            <br>
                        <?php endforeach ?>
                    <?php else : ?>
                        <?= session('errors') ?>
                    <?php endif ?>
                </div>
            <?php endif ?>

            <?php if (session('message') !== null) : ?>
            <div class="alert alert-success" role="alert"><?= session('message') ?></div>
            <?php endif ?>

            <form action="<?= url_to('login') ?>" method="post">
                <?= csrf_field() ?>

                <!-- Email -->
                <div class="form-floating mb-3">
                    <input type="email" class="form-control rounded-pill" id="floatingEmailInput" name="email" inputmode="email" autocomplete="email" placeholder="nome@exemplo.com" value="<?= old('email') ?>" required>
                    <label for="floatingEmailInput">Email</label>
                </div>

                <!-- Password -->
                <div class="form-floating mb-3">
                    <input type="password" class="form-control rounded-pill" id="floatingPasswordInput" name="password" inputmode="text" autocomplete="current-password" placeholder="Senha" required>
                    <label for="floatingPasswordInput">Senha</label>
                </div>

                <!-- Remember me -->
                <?php if (setting('Auth.sessionConfig')['allowRemembering']): ?>
                    <div class="form-check mb-3">
                        <label class="form-check-label text-muted small">
                            <input type="checkbox" name="remember" class="form-check-input" <?php if (old('remember')): ?> checked <?php endif ?>>
                            Lembrar de mim
                        </label>
                    </div>
                <?php endif; ?>

                <div class="d-grid mb-4">
                    <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold shadow-sm">Entrar</button>
                </div>

                <div class="text-center small text-muted">
                    <?php if (setting('Auth.allowMagicLinkLogins')) : ?>
                        <p class="mb-1">Esqueceu a senha? <a href="<?= url_to('magic-link') ?>">Entrar com Link Mágico</a></p>
                    <?php endif ?>
                    
                    <?php if (setting('Auth.allowRegistration')) : ?>
                        <p class="mb-0">Não tem conta? <a href="<?= url_to('register') ?>" class="fw-bold">Cadastre-se</a></p>
                    <?php endif ?>
                </div>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
