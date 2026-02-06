<?= $this->extend('Layouts/main') ?>

<?= $this->section('title') ?>Dashboard Financeiro & Analytics<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mt-4 mb-4">
        <div>
            <h1 class="m-0">Dashboard Financeiro</h1>
            <p class="text-muted small">Visão geral da saúde financeira do portal</p>
        </div>
        <div>
            <button class="btn btn-outline-primary" onclick="window.print()">
                <i class="fas fa-print me-2"></i> Relatório
            </button>
        </div>
    </div>

    <!-- Cards de Métricas -->
    <div class="row g-4 mb-4">
        <!-- MRR -->
        <div class="col-xl-3 col-md-6">
            <div class="card bg-primary text-white h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-white-50 small text-uppercase fw-bold mb-1">MRR (Mensal)</div>
                            <div class="h2 fw-bold mb-0">R$ <?= number_format($mrr, 2, ',', '.') ?></div>
                        </div>
                        <div class="icon-circle bg-white bg-opacity-25 text-white p-3 rounded-circle">
                            <i class="fas fa-chart-line fa-lg"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white bg-opacity-10 border-0">
                    <small class="text-white-50"><i class="fas fa-info-circle me-1"></i> Receita recorrente ativa</small>
                </div>
            </div>
        </div>

        <!-- Receita Total -->
        <div class="col-xl-3 col-md-6">
            <div class="card bg-success text-white h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-white-50 small text-uppercase fw-bold mb-1">Receita Total</div>
                            <div class="h2 fw-bold mb-0">R$ <?= number_format($totalRevenue, 2, ',', '.') ?></div>
                        </div>
                        <div class="icon-circle bg-white bg-opacity-25 text-white p-3 rounded-circle">
                            <i class="fas fa-wallet fa-lg"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white bg-opacity-10 border-0">
                    <small class="text-white-50"><i class="fas fa-calendar me-1"></i> Acumulado histórico</small>
                </div>
            </div>
        </div>

        <!-- Assinantes Ativos -->
        <div class="col-xl-3 col-md-6">
            <div class="card bg-info text-white h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-white-50 small text-uppercase fw-bold mb-1">Assinantes Ativos</div>
                            <div class="h2 fw-bold mb-0"><?= $activeSubscribers ?></div>
                        </div>
                        <div class="icon-circle bg-white bg-opacity-25 text-white p-3 rounded-circle">
                            <i class="fas fa-users fa-lg"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white bg-opacity-10 border-0">
                    <small class="text-white-50"><i class="fas fa-user-check me-1"></i> Clientes pagantes</small>
                </div>
            </div>
        </div>

        <!-- Inadimplência -->
        <div class="col-xl-3 col-md-6">
            <div class="card bg-danger text-white h-100 border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-white-50 small text-uppercase fw-bold mb-1">Inadimplentes</div>
                            <div class="h2 fw-bold mb-0"><?= $overdueSubscribers ?></div>
                        </div>
                        <div class="icon-circle bg-white bg-opacity-25 text-white p-3 rounded-circle">
                            <i class="fas fa-exclamation-triangle fa-lg"></i>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white bg-opacity-10 border-0">
                    <small class="text-white-50"><i class="fas fa-ban me-1"></i> Cancelados: <?= $canceledSubscribers ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabela de Transações Recentes -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="m-0 fw-bold"><i class="fas fa-history me-2 text-primary"></i>Transações Recentes</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">ID</th>
                            <th>Cliente</th>
                            <th>Valor</th>
                            <th>Método</th>
                            <th>Status</th>
                            <th>Data</th>
                            <th>Gateway</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentTransactions)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">Nenhuma transação encontrada.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentTransactions as $trx): ?>
                                <tr>
                                    <td class="ps-4 text-muted small">#<?= $trx->id ?></td>
                                    <td class="fw-bold"><?= esc($trx->account_name) ?></td>
                                    <td>R$ <?= number_format($trx->amount, 2, ',', '.') ?></td>
                                    <td>
                                        <?php if ($trx->method === 'PIX'): ?>
                                            <span class="badge bg-success-soft text-success"><i class="brands fa-pix me-1"></i> PIX</span>
                                        <?php elseif ($trx->method === 'BOLETO'): ?>
                                            <span class="badge bg-warning-soft text-warning"><i class="fas fa-barcode me-1"></i> Boleto</span>
                                        <?php else: ?>
                                            <span class="badge bg-primary-soft text-primary"><i class="fas fa-credit-card me-1"></i> Cartão</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $statusBadge = match($trx->status) {
                                                'PAID', 'CONFIRMED', 'RECEIVED' => 'bg-success',
                                                'PENDING' => 'bg-warning text-dark',
                                                'OVERDUE', 'FAILED' => 'bg-danger',
                                                'REFUNDED' => 'bg-info',
                                                default => 'bg-secondary'
                                            };
                                        ?>
                                        <span class="badge <?= $statusBadge ?>"><?= $trx->status ?></span>
                                    </td>
                                    <td><?= date('d/m/Y H:i', strtotime($trx->created_at)) ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?= strtoupper($trx->gateway_code ?? 'ASAAS') ?></span>
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
