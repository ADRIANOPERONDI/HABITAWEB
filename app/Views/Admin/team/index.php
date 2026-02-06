<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Minha Equipe<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Gestão de Equipe<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="row mb-4 align-items-center">
    <div class="col-md-6">
        <p class="text-muted">Gerencie os corretores e gestores que têm acesso à sua conta.</p>
    </div>
    <div class="col-md-6 text-md-end">
        <a href="<?= site_url('admin/team/new') ?>" class="btn btn-primary rounded-pill px-4 shadow">
            <i class="fa-solid fa-user-plus me-2"></i> Adicionar Membro
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th class="ps-4">Membro</th>
                    <th>E-mail</th>
                    <th>Cargo</th>
                    <th>Data Cadastro</th>
                    <th class="text-end pe-4">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($team)): ?>
                    <tr>
                        <td colspan="5" class="py-5 text-center text-muted">
                            <i class="fa-solid fa-users-slash fa-3x mb-3 opacity-25"></i>
                            <p>Nenhum membro da equipe encontrado.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($team as $member): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center">
                                    <div class="avatar-sm bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center fw-bold me-3" style="width: 40px; height: 40px;">
                                        <?= strtoupper(substr($member->nome ?? $member->username, 0, 1)) ?>
                                    </div>
                                    <div>
                                        <h6 class="mb-0 fw-bold"><?= esc($member->nome ?? $member->username) ?></h6>
                                        <span class="text-muted small">ID: #<?= $member->id ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><?= esc($member->email) ?></td>
                            <td>
                                    $groups = $member->getGroups();
                                    $role = count($groups) > 0 ? $groups[0] : 'user';
                                    
                                    $badgeClass = match($role) {
                                        'imobiliaria_admin' => 'bg-info text-dark',
                                        'imobiliaria_corretor' => 'bg-secondary text-white',
                                        'superadmin' => 'bg-dark text-white',
                                        default => 'bg-light text-muted'
                                    };
                                    
                                    $roleLabel = lang('Auth.role_' . $role);
                                ?>
                                <span class="badge <?= $badgeClass ?> rounded-pill px-3"><?= $roleLabel ?></span>
                            </td>
                            <td><?= date('d/m/Y', strtotime($member->created_at)) ?></td>
                            <td class="text-end pe-4">
                                <div class="btn-group">
                                    <a href="<?= site_url('admin/team/' . $member->id . '/edit') ?>" class="btn btn-sm btn-outline-primary rounded-start">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            onclick="confirmAction('<?= site_url('admin/team/' . $member->id . '/delete') ?>', 'DELETE', 'Remover Membro?', 'Deseja realmente remover este membro da sua equipe?')">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
