<?= $this->extend(config('Auth')->views['layout']) ?>

<?= $this->section('title') ?>Cadastro<?= $this->endSection() ?>

<?= $this->section('main') ?>
<div class="container d-flex justify-content-center align-items-center min-vh-100 py-5">
    <div class="card shadow-lg border-0 rounded-4" style="width: 100%; max-width: 450px;">
        <div class="card-body p-5">
            <div class="text-center mb-4">
                <i class="fa-solid fa-user-plus text-primary fa-3x mb-2"></i>
                <h4 class="fw-bold">Crie sua Conta</h4>
                <p class="text-muted small">Junte-se ao Habitaweb</p>
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

            <form action="<?= url_to('register') ?>" method="post">
                <?= csrf_field() ?>

                <!-- Username -->
                <div class="form-floating mb-3">
                    <input type="text" class="form-control rounded-pill" id="floatingUsernameInput" name="username" inputmode="text" autocomplete="username" placeholder="Usuário" value="<?= old('username') ?>" required>
                    <label for="floatingUsernameInput">Nome de Usuário</label>
                </div>

                <!-- Email -->
                <div class="form-floating mb-3">
                    <input type="email" class="form-control rounded-pill" id="floatingEmailInput" name="email" inputmode="email" autocomplete="email" placeholder="nome@exemplo.com" value="<?= old('email') ?>" required>
                    <label for="floatingEmailInput">Email</label>
                </div>

                <!-- Password -->
                <div class="form-floating mb-3">
                    <input type="password" class="form-control rounded-pill" id="floatingPasswordInput" name="password" inputmode="text" autocomplete="new-password" placeholder="Senha" required>
                    <label for="floatingPasswordInput">Senha</label>
                </div>

                <!-- Password Confirm -->
                <div class="form-floating mb-4">
                    <input type="password" class="form-control rounded-pill" id="floatingPasswordConfirmInput" name="password_confirm" inputmode="text" autocomplete="new-password" placeholder="Confirme a Senha" required>
                    <label for="floatingPasswordConfirmInput">Confirme a Senha</label>
                </div>

                <div class="d-grid mb-4">
                    <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold shadow-sm">Cadastrar</button>
                </div>

                <div class="text-center small text-muted">
                    <p class="mb-0">Já tem conta? <a href="<?= url_to('login') ?>" class="fw-bold">Fazer Login</a></p>
                </div>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
