<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Usuários do Sistema<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Usuários do Sistema<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-4 animate-fade-in">
    <div>
        <h4 class="fw-bold mb-1">Equipe & Colaboradores</h4>
        <p class="text-muted small mb-0">Gerencie quem tem acesso ao painel administrativo.</p>
    </div>
</div>

<div class="card card-premium overflow-hidden border-0 animate-fade-in" style="animation-delay: 0.1s">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4">Usuário</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Cadastro</th>
                        <th class="text-end pe-4">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($users)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">Nenhum usuário encontrado.</td></tr>
                    <?php else: ?>
                        <?php foreach($users as $u): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="bg-primary-soft text-primary rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 32px; height: 32px;">
                                        <?= substr($u->username, 0, 1) ?>
                                    </div>
                                    <span class="fw-bold text-dark"><?= esc($u->username) ?></span>
                                </div>
                            </td>
                            <td><small class="text-muted"><?= esc($u->email) ?></small></td>
                            <td>
                                <?php if ($u->active): ?>
                                    <span class="badge bg-tertiary-soft">Ativo</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">Inativo</span>
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted"><?= $u->created_at ? $u->created_at->format('d/m/Y') : '-' ?></small></td>
                            <td class="text-end pe-4">
                                <a href="<?= site_url('admin/users/' . $u->id . '/edit') ?>" class="btn btn-sm btn-light rounded-circle p-2" title="Editar"><i class="fa-solid fa-pen text-primary"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="d-flex justify-content-center mt-3">
    <?= $pager->links('default', 'bootstrap_full') ?>
</div>
<?= $this->endSection() ?>
