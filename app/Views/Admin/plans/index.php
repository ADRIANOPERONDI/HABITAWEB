<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Gestão de Planos<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Planos e Pacotes<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold text-gray-800">Planos Disponíveis</h5>
        <a href="<?= site_url('admin/plans/new') ?>" class="btn btn-primary btn-sm">
            <i class="fa-solid fa-plus me-1"></i> Novo Plano
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Nome</th>
                        <th>Preço (R$)</th>
                        <th>Imóveis</th>
                        <th>Fotos Max</th>
                        <th>Destaques</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($plans)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">Nenhum plano cadastrado.</td></tr>
                    <?php else: ?>
                        <?php foreach($plans as $plan): ?>
                        <tr>
                            <td class="ps-4 fw-bold"><?= esc($plan->nome) ?></td>
                            <td>R$ <?= number_format($plan->preco_mensal, 2, ',', '.') ?></td>
                            <td><?= $plan->limite_imoveis_ativos === null ? 'Ilimitado' : $plan->limite_imoveis_ativos ?></td>
                            <td><?= $plan->limite_fotos_por_imovel ?></td>
                            <td><?= $plan->destaques_mensais ?></td>
                            <td>
                                <?php if($plan->ativo): ?>
                                    <span class="badge bg-success">Ativo</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inativo</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4">
                                <a href="<?= site_url('admin/plans/' . $plan->id . '/edit') ?>" class="btn btn-sm btn-light" title="Editar">
                                    <i class="fa-solid fa-edit"></i>
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
<?= $this->endSection() ?>
