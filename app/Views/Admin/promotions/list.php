<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Meus Destaques<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Meus Destaques e Promoções</h1>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Imóvel</th>
                        <th>Pacote</th>
                        <th>Status</th>
                        <th>Válido até</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($promotions)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">Nenhuma promoção ativa.</td></tr>
                    <?php else: ?>
                        <?php foreach($promotions as $promo): ?>
                        <tr>
                            <td class="ps-4">
                                <a href="<?= site_url('imovel/' . $promo->property_id) ?>" target="_blank" class="fw-bold text-decoration-none">
                                    <?= esc($promo->titulo) ?> <i class="fa-solid fa-external-link-alt small text-muted ms-1"></i>
                                </a>
                            </td>
                            <td>
                                <span class="badge bg-warning text-dark">
                                    <i class="fa-solid fa-bolt me-1"></i> <?= esc($promo->pacote_key) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($promo->ativo): ?>
                                    <span class="badge bg-success">Ativo</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inativo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($promo->data_fim): ?>
                                    <?= date('d/m/Y', strtotime($promo->data_fim)) ?>
                                    <?php if(strtotime($promo->data_fim) < time()): ?>
                                        <span class="text-danger small">(Expirado)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4">
                                <a href="<?= site_url('admin/properties/' . $promo->property_id . '/turbo') ?>" class="btn btn-sm btn-outline-primary">
                                    Gerenciar
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
