<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Pagamentos<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Gestão Financeira<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .metric-card { border: 1px solid #f0f0f0; border-radius: 20px; transition: all 0.3s; background: #fff; }
    .metric-card:hover { border-color: var(--primary-color); transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
    .metric-icon { width: 56px; height: 56px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
    
    .bg-primary-soft { background-color: rgba(var(--primary-rgb), 0.1) !important; color: var(--primary-color) !important; }
    .bg-secondary-soft { background-color: rgba(var(--secondary-rgb), 0.1) !important; color: var(--secondary-color) !important; }
    .bg-success-soft { background-color: rgba(var(--tertiary-rgb), 0.1) !important; color: var(--tertiary-color) !important; }
    .bg-warning-soft { background-color: rgba(255, 193, 7, 0.1); color: #ffc107; }
    
    .card-dashboard-header {
        background: var(--primary-gradient);
        border-radius: 20px;
        min-height: 180px;
        display: flex;
        align-items: center;
        position: relative;
        overflow: hidden;
    }
    .card-dashboard-header .illustration {
        position: absolute;
        right: 0;
        bottom: 0;
        height: 100%;
        opacity: 0.15;
        z-index: 0;
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<!-- Header Premium -->
<div class="row mb-5 animate-fade-in">
    <div class="col-12">
        <div class="card card-dashboard-header border-0 shadow-lg text-white">
            <div class="card-body p-5 z-1">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h2 class="fw-bold mb-2">Resumo Financeiro 💰</h2>
                        <p class="opacity-75 mb-4">Acompanhe suas receitas, assinaturas e o desempenho das transações do portal.</p>
                        <div class="d-flex gap-2">
                            <a href="<?= site_url('admin/payments/transactions') ?>" class="btn btn-white rounded-pill px-4 bg-white text-primary border-0 fw-bold shadow-sm">
                                <i class="fa-solid fa-list-check me-2"></i> Ver Transações
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="illustration h-100">
                <i class="fa-solid fa-receipt h-100" style="font-size: 10rem; transform: rotate(-15deg) translateY(20%);"></i>
            </div>
        </div>
    </div>
</div>

<!-- Cards de Estatísticas -->
<div class="row g-4 mb-5 animate-fade-in" style="animation-delay: 0.1s">
    <!-- Receita do Mês -->
    <div class="col-md-3">
        <div class="card metric-card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="metric-icon bg-primary-soft">
                        <i class="fa-solid fa-sack-dollar"></i>
                    </div>
                </div>
                <h3 class="fw-bold mb-1 text-dark">R$ <?= number_format($monthRevenue, 2, ',', '.') ?></h3>
                <p class="text-muted small fw-bold mb-0">Receita do Mês</p>
                <div class="mt-2">
                    <span class="badge bg-light text-primary py-1 px-2 rounded-pill small">Mês Atual</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Assinaturas Ativas -->
    <div class="col-md-3">
        <div class="card metric-card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="metric-icon bg-success-soft">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <span class="badge bg-tertiary text-white rounded-pill">Ativo</span>
                </div>
                <h3 class="fw-bold mb-1 text-dark"><?= $subscriptionStats['active'] ?></h3>
                <p class="text-muted small fw-bold mb-0">Assinaturas Ativas</p>
                <div class="mt-2 small text-muted">
                    de <?= $subscriptionStats['total'] ?> total (histórico)
                </div>
            </div>
        </div>
    </div>

    <!-- Pagamentos Pendentes -->
    <div class="col-md-3">
        <div class="card metric-card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="metric-icon bg-warning-soft">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                </div>
                <h3 class="fw-bold mb-1 text-dark"><?= $transactionStats['pending'] ?></h3>
                <p class="text-muted small fw-bold mb-0">Transações Pendentes</p>
                <div class="mt-2 small text-warning">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> Aguardando Confirmação
                </div>
            </div>
        </div>
    </div>

    <!-- Taxa de Sucesso -->
    <div class="col-md-3">
        <div class="card metric-card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="metric-icon bg-secondary-soft">
                        <i class="fa-solid fa-percent"></i>
                    </div>
                </div>
                <h3 class="fw-bold mb-1 text-dark"><?= $transactionStats['success_rate'] ?>%</h3>
                <p class="text-muted small fw-bold mb-0">Taxa de Conversão</p>
                <div class="mt-2 small text-muted">
                    <?= $transactionStats['success'] ?> pagamentos confirmados
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-5">
    <!-- Gráfico de Receita -->
    <div class="col-md-8 animate-fade-in" style="animation-delay: 0.2s">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
            <div class="card-header bg-white border-0 p-4 pb-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-chart-area text-primary me-2"></i> Evolução da Receita (6 Meses)</h5>
                <span class="badge bg-light text-primary py-2 px-3 rounded-pill fw-bold">Total Acumulado: R$ <?= number_format(array_sum(array_column($monthlyRevenue, 'revenue')), 2, ',', '.') ?></span>
            </div>
            <div class="card-body p-4">
                <div style="height: 320px;">
                    <canvas id="revenueChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Status das Assinaturas -->
    <div class="col-md-4 animate-fade-in" style="animation-delay: 0.3s">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-0 p-4 pb-0">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-pie-chart text-secondary me-2"></i> Status das Assinaturas</h5>
            </div>
            <div class="card-body p-4">
                <div class="d-flex flex-column gap-3">
                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded-4 border-start border-4 border-success">
                        <div class="d-flex align-items-center">
                            <i class="fa-solid fa-check-circle text-success me-3"></i> 
                            <div>
                                <div class="text-dark fw-bold mb-0">Ativas</div>
                                <small class="text-muted">Acesso Liberado</small>
                            </div>
                        </div>
                        <h4 class="mb-0 fw-bold text-success"><?= $subscriptionStats['active'] ?></h4>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded-4 border-start border-4 border-warning">
                        <div class="d-flex align-items-center">
                            <i class="fa-solid fa-hourglass-start text-warning me-3"></i> 
                            <div>
                                <div class="text-dark fw-bold mb-0">Pendentes</div>
                                <small class="text-muted">Aguardando Gateway</small>
                            </div>
                        </div>
                        <h4 class="mb-0 fw-bold text-warning"><?= $subscriptionStats['pending'] ?></h4>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded-4 border-start border-4 border-danger">
                        <div class="d-flex align-items-center">
                            <i class="fa-solid fa-times-circle text-danger me-3"></i> 
                            <div>
                                <div class="text-dark fw-bold mb-0">Canceladas</div>
                                <small class="text-muted">Sem Acesso</small>
                            </div>
                        </div>
                        <h4 class="mb-0 fw-bold text-danger"><?= $subscriptionStats['inactive'] ?></h4>
                    </div>
                </div>
                
                <div class="mt-4 p-3 rounded-4 border border-dashed text-center bg-light bg-opacity-50">
                    <p class="text-muted extra-small mb-0"><i class="fa-solid fa-info-circle me-1"></i> Dados sincronizados em tempo real com seu provedor de pagamentos.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (auth()->user()->inGroup('superadmin')): ?>
<div class="mt-4 text-center animate-fade-in" style="animation-delay: 0.4s">
   <div class="p-3 bg-white shadow-sm rounded-pill border border-primary-soft d-inline-block px-4">
       <span class="text-primary small fw-bold"><i class="fa-solid fa-user-shield me-2"></i> Administrador: Modo de Visualização Global Ativado</span>
   </div>
</div>
<?php endif; ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
$(document).ready(function() {
    const ctx = document.getElementById('revenueChart').getContext('2d');
    const revenueData = <?= json_encode($monthlyRevenue) ?>;

    function hexToRgb(hex) {
        const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return result ? `${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)}` : '99, 102, 241';
    }

    const primaryHex = '<?= app_setting('style.primary_color', '#6366f1') ?>';
    const primaryRGB = hexToRgb(primaryHex);

    const gradient = ctx.createLinearGradient(0, 0, 0, 300);
    gradient.addColorStop(0, `rgba(${primaryRGB}, 0.2)`);
    gradient.addColorStop(1, `rgba(${primaryRGB}, 0)`);

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: revenueData.map(d => d.month),
            datasets: [{
                label: 'Receita (R$)',
                data: revenueData.map(d => d.revenue),
                borderColor: '<?= app_setting('style.primary_color', '#6366f1') ?>',
                borderWidth: 4,
                backgroundColor: gradient,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#fff',
                pointBorderColor: '<?= app_setting('style.primary_color', '#6366f1') ?>',
                pointBorderWidth: 3,
                pointRadius: 6,
                pointHoverRadius: 8,
                pointHoverBorderWidth: 3,
                pointHoverBackgroundColor: '<?= app_setting('style.primary_color', '#6366f1') ?>'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b',
                    padding: 12,
                    titleFont: { size: 14, weight: 'bold' },
                    bodyFont: { size: 13 },
                    cornerRadius: 8,
                    callbacks: {
                        label: function(context) {
                            return 'Receita: R$ ' + context.raw.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { borderDash: [5, 5], color: '#f1f5f9', drawBorder: false },
                    ticks: {
                        color: '#94a3b8',
                        font: { size: 11 },
                        callback: function(value) {
                            return 'R$ ' + value.toLocaleString('pt-BR');
                        }
                    }
                },
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#94a3b8', font: { size: 11 } }
                }
            }
        }
    });
});
</script>
<?= $this->endSection() ?>
