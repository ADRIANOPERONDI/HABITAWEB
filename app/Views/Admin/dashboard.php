<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Visão Geral<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Dashboard<?= $this->endSection() ?>

</div>

<?= $this->section('styles') ?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
<style>
    .metric-card { border: 1px solid #f0f0f0; border-radius: 20px; transition: all 0.3s; }
    .metric-card:hover { border-color: var(--primary-color); transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
    .metric-icon { width: 56px; height: 56px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
    .recent-img-mini { width: 60px; height: 60px; border-radius: 12px; object-fit: cover; }
    .quick-action-card { border-radius: 20px; border: 1px dashed #ddd; transition: all 0.3s; background: #fafafa; }
    .quick-action-card:hover { background: #fff; border-color: var(--primary-color); color: var(--primary-color) !important; }
    .system-logo-dash { max-height: 50px; width: auto; object-fit: contain; }
    
    /* Select2 Tweaks */
    .select2-container .select2-selection--single { height: 44px; display: flex; align-items: center; border-radius: 10px; border: 1px solid #dfe3e8; }
    .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered { padding-left: 15px; font-size: 0.95rem; color: #495057; }
    .filter-bar { background: #fff; padding: 20px; border-radius: 16px; border: 1px solid #eff2f5; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
    .btn-filter { height: 44px; border-radius: 10px; font-weight: 600; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row mb-5 animate-fade-in">
    <div class="col-12">
        <div class="card card-premium overflow-hidden border-0" style="background: var(--primary-gradient); min-height: 200px;">
            <div class="card-body p-5 d-flex align-items-center justify-content-between position-relative">
                <div class="text-white z-1">
                    <?php if ($logo = app_setting('style.logo_url')): ?>
                        <img src="<?= base_url($logo) ?>" class="system-logo-dash mb-3" alt="Logo">
                    <?php endif; ?>
                    <h2 class="fw-bold mb-2">Bom dia, <?= esc($userDisplayName) ?>! ✨</h2>
                    <p class="opacity-75 mb-4">Veja o que está acontecendo no <?= esc(app_setting('site.name', 'Portal')) ?> hoje.</p>
                    <div class="d-flex gap-2">
                        <a href="<?= site_url('admin/properties/new') ?>" class="btn btn-white rounded-pill px-4 bg-white text-primary border-0 fw-bold">
                            <i class="fa-solid fa-plus-circle me-1"></i> Anunciar Agora
                        </a>
                        <a href="<?= site_url('admin/leads') ?>" class="btn btn-link text-white text-decoration-none fw-bold small opacity-100">
                             Ver meus Leads <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
                <div class="position-absolute end-0 top-0 h-100 opacity-25 d-none d-lg-block" style="width: 40%; background: url('https://preview.tabler.io/static/illustrations/undraw_house_searching_re_stk3.svg') no-repeat center right; background-size: contain;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="row mb-4 animate-fade-in" style="animation-delay: 0.05s">
    <div class="col-12">
        <form action="<?= current_url() ?>" method="GET" class="filter-bar d-flex flex-column flex-md-row gap-3 align-items-end">
             <div class="flex-grow-1 w-100">
                 <label class="form-label text-muted small fw-bold text-uppercase mb-1">Filtrar por Bairro</label>
                 <select class="form-select select2" name="bairro" id="filterBairro">
                     <option value="">Todos os Bairros</option>
                     <?php foreach($neighborhoods as $nb): ?>
                         <option value="<?= esc($nb) ?>" <?= ($filters['bairro'] == $nb) ? 'selected' : '' ?>><?= esc($nb) ?></option>
                     <?php endforeach; ?>
                 </select>
             </div>
             
             <div class="flex-grow-1 w-100">
                 <label class="form-label text-muted small fw-bold text-uppercase mb-1">Filtrar por Condomínio</label>
                 <select class="form-select select2" name="condominio" id="filterCondominio">
                     <option value="">Todos os Condomínios</option>
                     <?php foreach($condos as $cd): ?>
                         <option value="<?= esc($cd) ?>" <?= ($filters['condominio'] == $cd) ? 'selected' : '' ?>><?= esc($cd) ?></option>
                     <?php endforeach; ?>
                 </select>
             </div>

             <div class="d-flex gap-2 w-100 w-md-auto">
                 <button type="submit" class="btn btn-primary btn-filter px-4 w-100 w-md-auto">
                     <i class="fa-solid fa-filter me-2"></i> Filtrar
                 </button>
                 <?php if(!empty($filters['bairro']) || !empty($filters['condominio'])): ?>
                     <a href="<?= current_url() ?>" class="btn btn-light text-muted btn-filter px-3" title="Limpar Filtros">
                         <i class="fa-solid fa-times"></i>
                     </a>
                 <?php endif; ?>
             </div>
        </form>
    </div>
</div>

<?php if (isset($subscriptionAlert) && $subscriptionAlert): ?>
<div class="row mb-4 animate-fade-in" style="animation-delay: 0.05s">
    <div class="col-12">
        <div class="alert alert-<?= $subscriptionAlert['type'] ?> border-0 shadow-sm rounded-4 p-4 mb-0 d-flex align-items-center">
            <div class="metric-icon bg-white text-<?= $subscriptionAlert['type'] ?> me-4">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-1">Aviso de Assinatura</h6>
                <p class="mb-0 opacity-75"><?= $subscriptionAlert['message'] ?></p>
            </div>
            <div class="ms-auto">
                <a href="<?= site_url('admin/subscription/plans') ?>" class="btn btn-<?= $subscriptionAlert['type'] ?> rounded-pill px-4 fw-bold">Ver Planos</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4 mb-5 animate-fade-in" style="animation-delay: 0.1s">
    <!-- Imóveis Ativos -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card metric-card h-100 border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="metric-icon bg-primary-soft text-primary">
                        <i class="fa-solid fa-house-circle-check"></i>
                    </div>
                </div>
                <h3 class="fw-bold mb-1"><?= $stats['imoveis_ativos'] ?></h3>
                <p class="text-muted small fw-bold mb-0">Imóveis Ativos</p>
                <div class="mt-2 small text-muted">
                    <span class="badge bg-light text-dark">Limite: <?= $stats['limit'] ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Leads Hoje -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card metric-card h-100 border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="metric-icon bg-secondary-soft text-secondary">
                        <i class="fa-solid fa-comment-dots"></i>
                    </div>
                    <span class="badge bg-tertiary text-white">Novo</span>
                </div>
                <h3 class="fw-bold mb-1"><?= $stats['leads_hoje'] ?></h3>
                <p class="text-muted small fw-bold mb-0">Leads Recebidos Hoje</p>
                <p class="mt-2 small text-tertiary mb-0">
                    <i class="fa-solid fa-bolt me-1"></i> Responda rápido!
                </p>
            </div>
        </div>
    </div>

    <!-- Visitas -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card metric-card h-100 border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="metric-icon bg-tertiary-soft text-tertiary">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                </div>
                <h3 class="fw-bold mb-1"><?= $stats['visitas_total'] ?></h3>
                <p class="text-muted small fw-bold mb-0">Visualizações Totais</p>
                <p class="mt-2 small text-muted mb-0">Alcance acumulado</p>
            </div>
        </div>
    </div>

    <!-- Plano -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card metric-card h-100 border-0 shadow-sm bg-dark text-white">
            <div class="card-body p-4 text-center">
                <div class="mb-3 d-flex justify-content-center">
                    <div class="metric-icon bg-white text-dark">
                        <i class="fa-solid fa-crown text-warning"></i>
                    </div>
                </div>
                <h4 class="fw-bold mb-1"><?= $stats['plano'] ?></h4>
                <p class="text-white-50 small mb-3">Assinatura Ativa</p>
                <div class="d-flex justify-content-center gap-2">
                    <small class="badge bg-white text-dark bg-opacity-10">Conv: <?= $stats['conversion_rate'] ?>%</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ANALYTICS ROW -->
<div class="row mb-5 g-4 animate-fade-in" style="animation-delay: 0.15s">
    <!-- Chart: Leads Performance -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 p-4 pb-0">
                <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-area text-primary me-2"></i> Performance de Leads (7 dias)</h5>
            </div>
            <div class="card-body p-4">
                <canvas id="leadsChart" height="100"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Market Comparison & Opportunities -->
    <div class="col-lg-4">
        <div class="d-flex flex-column gap-4 h-100">
            
            <!-- Market Price -->
            <div class="card border-0 shadow-sm flex-grow-1">
                <div class="card-body p-4">
                    <h6 class="fw-bold text-muted text-uppercase small mb-3">Comparativo de Mercado</h6>
                    <div class="d-flex align-items-end gap-3 mb-2">
                         <h3 class="fw-bold mb-0">R$ <?= $stats['avg_ticket'] ?></h3>
                         <small class="<?= $stats['ticket_status'] === 'above' ? 'text-success' : 'text-danger' ?>">
                             <i class="fa-solid fa-arrow-<?= $stats['ticket_status'] === 'above' ? 'up' : 'down' ?>"></i> vs Média
                         </small>
                    </div>
                    <p class="text-muted small">Seu valor médio vs R$ <?= $stats['market_avg_ticket'] ?> (Mercado)</p>
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar bg-primary" role="progressbar" style="width: 60%"></div>
                    </div>
                </div>
            </div>

            <!-- Opportunities Alert -->
            <div class="card border-0 shadow-sm flex-grow-1 bg-warning-soft">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold text-dark text-uppercase small mb-0"><i class="fa-solid fa-lightbulb text-warning me-2"></i> Oportunidades</h6>
                        <span class="badge bg-warning text-dark"><?= count($opportunities) ?></span>
                    </div>
                    
                    <?php if(empty($opportunities)): ?>
                        <p class="small text-muted mb-0">Nenhuma oportunidade crítica detectada. Seus imóveis estão performando bem!</p>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                        <?php foreach($opportunities as $opp): ?>
                             <div class="d-flex align-items-center bg-white p-2 rounded shadow-sm">
                                 <div class="flex-grow-1 ms-2">
                                     <div class="details small fw-bold text-dark text-truncate" style="max-width: 150px;"><?= esc($opp->titulo) ?></div>
                                     <div class="text-muted extra-small"><?= $opp->visitas_count ?> views • 0 leads</div>
                                 </div>
                                 <a href="<?= site_url('admin/properties/' . $opp->id . '/edit') ?>" class="btn btn-sm btn-light text-primary"><i class="fa-solid fa-pen"></i></a>
                             </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('leadsChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartData['labels']) ?>,
            datasets: [{
                label: 'Leads',
                data: <?= json_encode($chartData['values']) ?>,
                borderColor: 'var(--primary-color)',
                backgroundColor: 'rgba(var(--primary-rgb), 0.1)',
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } },
                x: { grid: { display: false } }
            }
        }
    });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Selecione...',
            allowClear: true
        });
    });
</script>

<?php if ($stats['is_global']): ?>
<div class="row g-4 mb-5 animate-fade-in" style="animation-delay: 0.2s">
    <div class="col-12">
        <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-globe me-2 text-primary"></i> Visão Global do Portal</h5>
    </div>
    <!-- Total Imóveis -->
    <div class="col-12 col-md-4">
        <div class="card metric-card h-100 border-0 shadow-sm bg-primary text-white">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-white-50 small fw-bold text-uppercase">Total de Anúncios</span>
                    <i class="fa-solid fa-house-circle-exclamation opacity-50"></i>
                </div>
                <h2 class="fw-bold mb-0"><?= $stats['total_imoveis_global'] ?></h2>
            </div>
        </div>
    </div>
    <!-- Total Contas -->
    <div class="col-12 col-md-4">
        <div class="card metric-card h-100 border-0 shadow-sm" style="background: var(--secondary-gradient); color: #fff;">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-white-50 small fw-bold text-uppercase">Imobiliárias & Corretores</span>
                    <i class="fa-solid fa-users opacity-50"></i>
                </div>
                <h2 class="fw-bold mb-0"><?= $stats['total_contas_global'] ?></h2>
            </div>
        </div>
    </div>
    <!-- Total Leads -->
    <div class="col-12 col-md-4">
        <div class="card metric-card h-100 border-0 shadow-sm bg-tertiary text-white">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-white-50 small fw-bold text-uppercase">Leads Gerados (Total)</span>
                    <i class="fa-solid fa-paper-plane opacity-50"></i>
                </div>
                <h2 class="fw-bold mb-0"><?= $stats['total_leads_global'] ?></h2>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 p-4 pb-0 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold text-dark mb-0">Imóveis Atualizados Recentemente</h5>
                <a href="<?= site_url('admin/properties') ?>" class="btn btn-link text-primary text-decoration-none fw-bold small">Ver Todos</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <tbody>
                            <?php if(empty($recentProperties)): ?>
                                <tr>
                                    <td class="text-center py-5 text-muted">Ainda não há imóveis para exibir.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($recentProperties as $prop): ?>
                                <tr onclick="window.location='<?= site_url('admin/properties/' . $prop->id . '/edit') ?>'" style="cursor: pointer;">
                                    <td class="ps-4 py-3">
                                        <div class="d-flex align-items-center gap-3">
                                            <img src="<?= $prop->cover_image ? base_url($prop->cover_image) : base_url('assets/img/placeholder-house.png') ?>" class="recent-img-mini" alt="Capa">
                                            <div>
                                                <div class="fw-bold text-dark"><?= esc($prop->titulo) ?></div>
                                                <div class="small text-muted"><?= esc($prop->bairro) ?>, <?= esc($prop->cidade) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark">R$ <?= number_format($prop->preco, 2, ',', '.') ?></div>
                                        <div class="small text-muted"><?= esc($prop->tipo_negocio) ?></div>
                                    </td>
                                    <td>
                                        <?php if($prop->status === 'ACTIVE'): ?>
                                            <span class="badge bg-success-soft text-success rounded-pill px-3">Ativo</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted rounded-pill px-3"><?= $prop->status ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <i class="fa-solid fa-chevron-right text-light"></i>
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

    <div class="col-lg-4">
        <div class="d-grid gap-3">
            <h5 class="fw-bold text-dark mb-1">Ações Rápidas</h5>
            
            <a href="<?= site_url('admin/properties/new') ?>" class="card quick-action-card text-decoration-none p-4 d-flex align-items-center flex-row gap-3">
                <div class="metric-icon bg-white shadow-sm text-primary">
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div>
                    <h6 class="fw-bold mb-0">Novo Anúncio</h6>
                    <small class="text-muted">Cadastre um imóvel em minutos</small>
                </div>
            </a>

            <a href="<?= site_url('admin/clients/new') ?>" class="card quick-action-card text-decoration-none p-4 d-flex align-items-center flex-row gap-3">
                <div class="metric-icon bg-white shadow-sm text-secondary">
                    <i class="fa-solid fa-user-plus"></i>
                </div>
                <div>
                    <h6 class="fw-bold mb-0">Novo Cliente</h6>
                    <small class="text-muted">Adicione um novo proprietário</small>
                </div>
            </a>

            <a href="<?= site_url('admin/promotions') ?>" class="card quick-action-card text-decoration-none p-4 d-flex align-items-center flex-row gap-3">
                <div class="metric-icon bg-white shadow-sm text-tertiary">
                    <i class="fa-solid fa-bolt"></i>
                </div>
                <div>
                    <h6 class="fw-bold mb-0">Turbinar Imóvel</h6>
                    <small class="text-muted">Aumente sua visibilidade</small>
                </div>
            </a>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
