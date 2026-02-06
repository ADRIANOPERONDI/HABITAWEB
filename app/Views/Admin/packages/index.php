<?= $this->extend('Layouts/master') ?>

<?= $this->section('page_title') ?>Gerenciar Pacotes de Destaque<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="animate-fade-in">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">Gerenciar Pacotes</h1>
            <p class="text-muted small">Crie e edite os planos de "Turbinar" disponíveis para os anunciantes.</p>
        </div>
        <a href="<?= site_url('admin/packages/new') ?>" class="btn btn-primary rounded-pill">
            <i class="fa-solid fa-plus me-2"></i> Novo Pacote
        </a>
    </div>

    <?= view('App\Views\admin\partials\alerts') ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">Nome</th>
                            <th>Chave (Código)</th>
                            <th>Tipo</th>
                            <th>Duração</th>
                            <th>Preço</th>
                            <th class="text-end px-4">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($packages)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fa-regular fa-folder-open fs-1 mb-3 d-block"></i>
                                    Nenhum pacote cadastrado.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($packages as $pkg): ?>
                                <tr>
                                    <td class="px-4">
                                        <div class="fw-bold text-dark"><?= esc($pkg->nome) ?></div>
                                    </td>
                                    <td><code><?= esc($pkg->chave) ?></code></td>
                                    <td>
                                        <?php if($pkg->tipo_promocao == 'SUPER_DESTAQUE'): ?>
                                            <span class="badge bg-warning text-dark"><i class="fa-solid fa-star me-1"></i> Super Destaque</span>
                                        <?php elseif($pkg->tipo_promocao == 'VITRINE'): ?>
                                            <span class="badge bg-info text-dark"><i class="fa-solid fa-gem me-1"></i> Vitrine</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="fa-solid fa-bolt me-1"></i> Destaque</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $pkg->duracao_dias ?> dias</td>
                                    <td class="fw-bold text-success">R$ <?= number_format($pkg->preco, 2, ',', '.') ?></td>
                                    <td class="text-end px-4">
                                        <a href="<?= site_url('admin/packages/edit/' . $pkg->id) ?>" class="btn btn-sm btn-outline-primary rounded-pill me-1">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>
                                        <button onclick="confirmDelete('<?= site_url('admin/packages/delete/' . $pkg->id) ?>')" class="btn btn-sm btn-outline-danger rounded-pill">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
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

<script>
function confirmDelete(url) {
    Swal.fire({
        title: 'Tem certeza?',
        text: "Esta ação não pode ser desfeita.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Sim, excluir!',
        cancelButtonText: 'Cancelar',
        borderRadius: '24px'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = url; // Or use fetch for DELETE method compliance if needed, but GET delete link is simpler for MVP
        }
    });
}
</script>
<?= $this->endSection() ?>
