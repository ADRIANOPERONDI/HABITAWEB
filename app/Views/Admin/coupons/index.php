<?= $this->extend('Layouts/master') ?>

<?= $this->section('content') ?>
<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mt-4 mb-4">
        <h1>Cupons de Desconto</h1>
        <a href="<?= site_url('admin/coupons/create') ?>" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i> Criar Cupom
        </a>
    </div>

    <?php if (session()->has('message')): ?>
        <div class="alert alert-success"><?= session('message') ?></div>
    <?php endif; ?>
    
    <?php if (session()->has('error')): ?>
        <div class="alert alert-danger"><?= session('error') ?></div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-ticket-alt me-1"></i> Lista de Cupons
        </div>
        <div class="card-body">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Desconto</th>
                        <th>Uso / Limite</th>
                        <th>Validade</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($coupons)): ?>
                        <tr><td colspan="6" class="text-center">Nenhum cupom encontrado.</td></tr>
                    <?php else: ?>
                        <?php foreach ($coupons as $c): ?>
                            <tr>
                                <td class="fw-bold"><?= esc($c->code) ?></td>
                                <td>
                                    <?php if ($c->discount_type === 'percent'): ?>
                                        <span class="badge bg-info text-dark"><?= $c->discount_value ?>% OFF</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">R$ <?= number_format($c->discount_value, 2, ',', '.') ?> OFF</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $c->used_count ?> / <?= is_null($c->max_uses) ? '∞' : $c->max_uses ?>
                                </td>
                                <td>
                                    <?php if ($c->valid_until): ?>
                                        <?= date('d/m/Y', strtotime($c->valid_until)) ?>
                                    <?php else: ?>
                                        <span class="text-muted">Indefinido</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($c->is_active): ?>
                                        <span class="badge bg-success">Ativo</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= site_url('admin/coupons/edit/' . $c->id) ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                                    <a href="<?= site_url('admin/coupons/delete/' . $c->id) ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Tem certeza?')">Excluir</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
