<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?><?= $member ? 'Editar Membro' : 'Novo Membro' ?><?= $this->endSection() ?>
<?= $this->section('page_title') ?><?= $member ? 'Configurações do Membro' : 'Adicionar Membro Equipe' ?><?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-4 p-4">
            <form action="<?= $member ? site_url('admin/team/' . $member->id . '/update') : site_url('admin/team') ?>" method="post">
                <?php if ($member): ?>
                    <input type="hidden" name="_method" value="POST">
                <?php endif; ?>

                <div class="mb-4">
                    <label class="form-label fw-bold">Nome Completo</label>
                    <input type="text" name="nome" class="form-control rounded-pill px-3" value="<?= old('nome', $member?->nome ?? '') ?>" required placeholder="Ex: João da Silva">
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">Nome de Usuário</label>
                    <input type="text" name="username" class="form-control rounded-pill px-3" value="<?= old('username', $member?->username ?? '') ?>" <?= $member ? 'readonly' : 'required' ?>>
                    <?php if (!$member): ?><small class="text-muted ps-2">Usado para login (sem espaços).</small><?php endif; ?>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">E-mail</label>
                    <input type="email" name="email" class="form-control rounded-pill px-3" value="<?= old('email', $member?->email ?? '') ?>" <?= $member ? 'readonly' : 'required' ?>>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold"><?= $member ? 'Nova Senha (deixe em branco para manter)' : 'Senha' ?></label>
                    <input type="password" name="password" class="form-control rounded-pill px-3" <?= $member ? '' : 'required' ?>>
                </div>

                <div class="mb-5">
                    <label class="form-label fw-bold">Cargo / Permissão</label>
                    <select name="role" class="form-select rounded-pill px-3" required>
                        <?php 
                            $currentRole = $member ? ($member->getGroups()[0] ?? 'imobiliaria_corretor') : 'imobiliaria_corretor';
                        ?>
                        <option value="imobiliaria_corretor" <?= $currentRole == 'imobiliaria_corretor' ? 'selected' : '' ?>><?= lang('Auth.role_imobiliaria_corretor') ?> (Acesso aos próprios imóveis)</option>
                        <option value="imobiliaria_admin" <?= $currentRole == 'imobiliaria_admin' ? 'selected' : '' ?>><?= lang('Auth.role_imobiliaria_admin') ?> (Acesso a tudo da conta)</option>
                    </select>
                </div>

                <div class="d-flex gap-3">
                    <button type="submit" class="btn btn-primary btn-lg rounded-pill px-5 shadow">
                        <i class="fa-solid fa-save me-2"></i> Salvar Membro
                    </button>
                    <a href="<?= site_url('admin/team') ?>" class="btn btn-outline-secondary btn-lg rounded-pill px-4">
                        Cancelar
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
