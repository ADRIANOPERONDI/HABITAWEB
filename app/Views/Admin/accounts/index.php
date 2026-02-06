<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Contas Registradas<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Contas Registradas<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="row mb-4 align-items-center">
    <div class="col-md-6">
        <p class="text-muted">Gerencie todos os anunciantes (PF e Imobiliárias) cadastrados na plataforma.</p>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 overflow-hidden animate-fade-in">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light text-muted small text-uppercase">
                <tr>
                    <th class="ps-4">ID</th>
                    <th>Nome / Razão Social</th>
                    <th>Tipo</th>
                    <th>Documento</th>
                    <th>Status</th>
                    <th>Cadastro</th>
                    <th class="text-end pe-4">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($accounts)): ?>
                    <tr>
                        <td colspan="7" class="py-5 text-center text-muted">
                            <i class="fa-solid fa-folder-open fa-3x mb-3 opacity-25"></i>
                            <p>Nenhuma conta encontrada.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach($accounts as $acc): ?>
                    <tr>
                        <td class="ps-4 text-muted small">#<?= $acc->id ?></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="avatar-sm bg-primary-soft text-primary rounded-circle d-flex align-items-center justify-content-center fw-bold me-3" style="width: 35px; height: 35px; background: rgba(99, 102, 241, 0.1);">
                                    <?= strtoupper(substr($acc->nome, 0, 1)) ?>
                                </div>
                                <span class="fw-bold text-dark"><?= esc($acc->nome) ?></span>
                            </div>
                        </td>
                        <td>
                            <?php 
                                $badgeClass = match($acc->tipo_conta) {
                                    'IMOBILIARIA' => 'bg-primary',
                                    'CORRETOR' => 'bg-info text-dark',
                                    'PF' => 'bg-secondary',
                                    default => 'bg-light text-muted'
                                };
                            ?>
                            <span class="badge <?= $badgeClass ?> rounded-pill px-3"><?= esc($acc->tipo_conta) ?></span>
                        </td>
                        <td><small class="text-muted"><?= esc($acc->documento) ?></small></td>
                        <td>
                            <?php if ($acc->status === 'ACTIVE'): ?>
                                <span class="badge bg-success-soft text-success rounded-pill px-3" style="background: rgba(25, 135, 84, 0.1);">Ativa</span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border rounded-pill px-3">Inativa</span>
                            <?php endif; ?>
                        </td>
                        <td><small class="text-muted"><?= $acc->created_at ? $acc->created_at->format('d/m/Y') : '-' ?></small></td>
                        <td class="text-end pe-4">
                            <?php if ($acc->id == auth()->user()->account_id): ?>
                                <span class="d-inline-block" data-bs-toggle="tooltip" title="Gerencie sua conta através do seu Perfil">
                                    <button class="btn btn-sm btn-light rounded-pill px-3 border" disabled style="cursor: not-allowed; opacity: 0.6;">
                                        <i class="fa-solid fa-user-lock text-muted me-1"></i> Editar
                                    </button>
                                </span>
                            <?php else: ?>
                                <a href="<?= site_url('admin/accounts/' . $acc->id . '/edit') ?>" class="btn btn-sm btn-light rounded-pill px-3 border" title="Editar">
                                    <i class="fa-solid fa-pen-to-square text-primary me-1"></i> Editar
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex justify-content-center mt-4">
    <?= $pager->links('default', 'bootstrap_full') ?>
</div>
<?= $this->endSection() ?>

