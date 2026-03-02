<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Verificação de Identidade<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Verificação de Identidade (Anti-Fraude)<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container-fluid px-4">
    <div class="mb-4 mt-4">
        <div class="btn-group" role="group">
            <a href="<?= site_url('admin/verification?status=PENDING') ?>" class="btn <?= $currentStatus === 'PENDING' ? 'btn-primary' : 'btn-outline-primary' ?>">
                Pendentes
            </a>
            <a href="<?= site_url('admin/verification?status=APPROVED') ?>" class="btn <?= $currentStatus === 'APPROVED' ? 'btn-primary' : 'btn-outline-primary' ?>">
                Aprovados
            </a>
            <a href="<?= site_url('admin/verification?status=REJECTED') ?>" class="btn <?= $currentStatus === 'REJECTED' ? 'btn-primary' : 'btn-outline-primary' ?>">
                Rejeitados
            </a>
        </div>
    </div>

    <?php if (session()->has('message')): ?>
        <div class="alert alert-success border-0 rounded-4"><?= session('message') ?></div>
    <?php endif; ?>
    
    <?php if (session()->has('error')): ?>
        <div class="alert alert-danger border-0 rounded-4"><?= session('error') ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3">
            <i class="fas fa-shield-alt me-1 text-primary"></i> Contas em Verificação
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Conta / Cliente</th>
                            <th>Documento</th>
                            <th>Enviado em</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($accounts)): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">Nenhuma conta encontrada com este status.</td></tr>
                        <?php else: ?>
                            <?php foreach ($accounts as $a): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="avatar-sm bg-primary-soft text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                <i class="fas fa-building"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold"><?= esc($a->nome) ?></div>
                                                <div class="xsmall text-muted"><?= esc($a->email) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="small fw-medium"><?= esc($a->documento) ?></td>
                                    <td class="small text-muted">
                                        <?= $a->updated_at ? date('d/m/Y H:i', strtotime($a->updated_at)) : 'N/A' ?>
                                    </td>
                                    <td>
                                        <?php if ($a->verification_status === 'APPROVED'): ?>
                                            <span class="badge bg-success-soft text-success rounded-pill px-3">Aprovado</span>
                                        <?php elseif ($a->verification_status === 'PENDING'): ?>
                                            <span class="badge bg-warning-soft text-warning rounded-pill px-3">Pendente</span>
                                        <?php elseif ($a->verification_status === 'REJECTED'): ?>
                                            <span class="badge bg-danger-soft text-danger rounded-pill px-3">Rejeitado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="<?= site_url('admin/verification/show/' . $a->id) ?>" class="btn btn-sm btn-primary rounded-pill px-3">
                                            Revisar Documentos
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
