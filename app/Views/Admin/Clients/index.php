<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Meus Clientes<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Gestão de Clientes<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .filter-bar-airbnb { background: #fff; border-radius: 50px; padding: 10px 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #f0f0f0; margin-bottom: 2rem; }
    .filter-input { border: none !important; background: transparent !important; box-shadow: none !important; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row align-items-center mb-4 g-3">
    <div class="col-lg-8">
        <form action="<?= current_url() ?>" method="get" class="filter-bar-airbnb d-flex align-items-center gap-2">
            <div class="flex-grow-1 border-end pe-3">
                <div class="d-flex align-items-center">
                    <i class="fa-solid fa-magnifying-glass text-muted me-2"></i>
                    <input type="text" name="term" class="form-control filter-input" placeholder="Buscar por nome, email ou CPF/CNPJ..." value="<?= esc($filters['term'] ?? '') ?>">
                </div>
            </div>
            
            <?php if (!empty($accounts)): ?>
            <div class="px-3">
                <select name="account_id" class="form-select filter-input fw-bold" style="width: auto; max-width: 250px;">
                    <option value="">Todas as Contas</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?= $acc->id ?>" <?= ($filters['account_id'] ?? '') == $acc->id ? 'selected' : '' ?>>
                            <?= esc($acc->nome) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary rounded-circle p-2 px-3">
                <i class="fa-solid fa-arrow-right"></i>
            </button>
        </form>
    </div>
    
    <div class="col-lg-4 text-lg-end">
        <a href="<?= site_url('admin/clients/new') ?>" class="btn btn-primary btn-lg rounded-pill px-4 shadow">
            <i class="fa-solid fa-plus me-2"></i> Novo Cliente
        </a>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">Proprietários & Contatos</h4>
        <p class="text-muted small mb-0">Gerencie as pessoas vinculadas aos imóveis.</p>
    </div>
</div>

<div class="card card-premium overflow-hidden border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="ps-4">Cliente</th>
                        <th>Contato</th>
                        <th>CPF/CNPJ</th>
                        <th>Tipo</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clients)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5">
                                <i class="fa-regular fa-address-book fa-3x text-muted mb-3 d-block"></i>
                                <p class="text-muted">Nenhum cliente cadastrado ainda.</p>
                                <a href="<?= site_url('admin/clients/new') ?>" class="btn btn-outline-primary btn-sm rounded-pill">Cadastrar Primeiro</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clients as $client): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-secondary-soft text-secondary rounded-circle d-flex align-items-center justify-content-center fw-bold me-3" style="width: 40px; height: 40px;">
                                            <?= substr($client->nome, 0, 1) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= esc($client->nome) ?></div>
                                            <div class="small text-muted">ID #<?= $client->id ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-dark small"><i class="fa-regular fa-envelope me-1 text-muted"></i> <?= esc($client->email) ?></div>
                                    <div class="text-dark small"><i class="fa-solid fa-phone me-1 text-muted"></i> <?= esc($client->telefone) ?></div>
                                </td>
                                <td><?= esc($client->cpf_cnpj ?: '---') ?></td>
                                <td>
                                    <span class="badge bg-secondary-soft">
                                        <?= esc($client->tipo_cliente) ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <a href="<?= site_url('admin/clients/' . $client->id . '/edit') ?>" class="btn btn-sm btn-light border" title="Editar">
                                        <i class="fa-solid fa-pen text-primary"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-light border text-danger" title="Excluir">
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
<?= $this->endSection() ?>
