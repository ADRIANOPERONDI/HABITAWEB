<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Transações<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Histórico Financeiro<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .metric-card { border: 1px solid #f0f0f0; border-radius: 20px; transition: all 0.3s; background: #fff; }
    .filter-bar { background: #fff; padding: 25px; border-radius: 20px; border: 1px solid #eff2f5; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
    .table-premium thead th { background-color: #f8fafc; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.025em; color: #64748b; padding: 1rem 1.5rem; border-bottom: 1px solid #e2e8f0; }
    .table-premium tbody td { padding: 1.25rem 1.5rem; color: #1e293b; border-bottom: 1px solid #f1f5f9; }
    .btn-view-transaction { width: 38px; height: 38px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #f1f5f9; color: var(--primary-color); transition: all 0.2s; }
    .btn-view-transaction:hover { background: var(--primary-color); color: #fff; transform: scale(1.1); }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-4 animate-fade-in">
    <div>
        <h4 class="fw-bold mb-1"><i class="fa-solid fa-receipt text-primary me-2"></i> Transações</h4>
        <p class="text-muted small mb-0">Listagem detalhada de todas as movimentações financeiras geradas pelo portal.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= site_url('admin/payments/dashboard') ?>" class="btn btn-light rounded-pill px-4 border shadow-sm">
            <i class="fa-solid fa-chart-line me-2"></i> Ver Dashboard
        </a>
        <a href="<?= site_url('admin/payments/export-transactions?' . http_build_query($this->request->getGet())) ?>" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
            <i class="fa-solid fa-download me-2"></i> Exportar
        </a>
    </div>
</div>

<!-- Filtros Premium -->
<div class="filter-bar mb-4 animate-fade-in" style="animation-delay: 0.1s">
    <form id="filterForm" class="row g-3 align-items-end">
        <?php if ($isAdmin): ?>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-muted text-uppercase mb-1">Entidade/Conta</label>
            <select name="account_id" class="form-select border-0 bg-light rounded-3 py-2">
                <option value="">Todas as contas</option>
                <?php foreach ($accounts as $account): ?>
                    <option value="<?= $account->id ?>"><?= esc($account->nome ?? $account->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="col-md-2">
            <label class="form-label small fw-bold text-muted text-uppercase mb-1">Status</label>
            <select name="status" class="form-select border-0 bg-light rounded-3 py-2">
                <option value="">Todos Status</option>
                <option value="CONFIRMED">✅ Confirmado</option>
                <option value="PENDING">⏳ Pendente</option>
                <option value="FAILED">❌ Falhou</option>
                <option value="CANCELLED">🚫 Cancelado</option>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label small fw-bold text-muted text-uppercase mb-1">Pagamento</label>
            <select name="payment_method" class="form-select border-0 bg-light rounded-3 py-2">
                <option value="">Todos Métodos</option>
                <option value="PIX">⚡ PIX</option>
                <option value="BOLETO">📄 Boleto</option>
                <option value="CREDIT_CARD">💳 Cartão</option>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label small fw-bold text-muted text-uppercase mb-1">Período</label>
            <input type="date" name="start_date" class="form-control border-0 bg-light rounded-3 py-2" placeholder="Início">
        </div>

        <div class="col-md-1">
            <button type="submit" class="btn btn-primary w-100 rounded-3 py-2 shadow-sm">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
        </div>
    </form>
</div>

<!-- Tabela Premium -->
<div class="card card-premium overflow-hidden border-0 shadow-lg animate-fade-in" style="animation-delay: 0.2s">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="transactionsTable" class="table table-hover table-premium align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Código</th>
                        <th>Data e Hora</th>
                        <?php if ($isAdmin): ?>
                            <th>Conta Responsável</th>
                        <?php endif; ?>
                        <th>Método</th>
                        <th>Valor R$</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Ação</th>
                    </tr>
                </thead>
                <tbody class="small">
                    <!-- DataTables -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.1/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.1/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.1/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
    
    const table = $('#transactionsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '<?= site_url('admin/payments/get-transactions') ?>',
            data: function(d) {
                d.account_id = $('select[name="account_id"]').val();
                d.status = $('select[name="status"]').val();
                d.payment_method = $('select[name="payment_method"]').val();
                d.start_date = $('input[name="start_date"]').val();
            }
        },
        columns: [
            { 
                data: 'id',
                className: 'ps-4',
                render: function(data) {
                    return `<span class="badge bg-light text-primary border border-primary-soft py-2 px-3">#${data}</span>`;
                }
            },
            { 
                data: 'created_at',
                render: function(data) {
                    const date = new Date(data);
                    return `<div class="fw-bold text-dark">${date.toLocaleDateString('pt-BR')}</div><small class="text-muted">${date.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'})}</small>`;
                }
            },
            ...(isAdmin ? [{
                data: 'account_name',
                render: function(data) {
                    return `<div class="fw-bold text-dark small">${data || 'N/A'}</div>`;
                }
            }] : []),
            { 
                data: 'payment_method',
                render: function(data) {
                    const icons = {
                        'PIX': 'fa-bolt text-primary',
                        'BOLETO': 'fa-barcode text-warning',
                        'CREDIT_CARD': 'fa-credit-card text-success'
                    };
                    const badges = {
                        'PIX': 'primary',
                        'BOLETO': 'warning',
                        'CREDIT_CARD': 'success'
                    };
                    return `<div class="d-flex align-items-center gap-2">
                        <i class="fa-solid ${icons[data] || 'fa-money-bill text-secondary'}"></i>
                        <span class="fw-bold text-dark">${data}</span>
                    </div>`;
                }
            },
            { 
                data: 'amount',
                render: function(data) {
                    return `<div class="fw-bold text-dark">R$ ${parseFloat(data).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>`;
                }
            },
            { 
                data: 'status',
                render: function(data) {
                    const colors = {
                        'CONFIRMED': 'success',
                        'PENDING': 'warning',
                        'FAILED': 'danger',
                        'CANCELLED': 'secondary'
                    };
                    const labels = {
                        'CONFIRMED': 'Confirmado',
                        'PENDING': 'Pendente',
                        'FAILED': 'Falhou',
                        'CANCELLED': 'Cancelado'
                    };
                    return `<span class="badge bg-${colors[data] || 'secondary'} rounded-pill px-3 py-2 small">${labels[data] || data}</span>`;
                }
            },
            {
                data: 'id',
                orderable: false,
                className: 'text-end pe-4',
                render: function(data) {
                    return `<a href="<?= site_url('admin/payments/transaction/') ?>${data}" class="btn-view-transaction ms-auto" title="Ver Detalhes">
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>`;
                }
            }
        ],
        order: [[1, 'desc']],
        pageLength: 10,
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.1/i18n/pt-BR.json'
        },
        drawCallback: function() {
            $('.dataTables_paginate > .pagination').addClass('pagination-rounded justify-content-center mt-4');
        }
    });
    
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        table.ajax.reload();
    });
});
</script>
<?= $this->endSection() ?>
