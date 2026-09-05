<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Visão Geral<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Dashboard<?= $this->endSection() ?>

</div>

<?= $this->section('styles') ?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
<style>
    .metric-card { border: 1px solid var(--admin-border); border-radius: 20px; transition: all 0.3s; background-color: var(--admin-card-bg); }
    .metric-card:hover { border-color: var(--primary-color); transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.05); }
    .metric-icon { width: 56px; height: 56px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
    .recent-img-mini { width: 60px; height: 60px; border-radius: 12px; object-fit: cover; }
    .quick-action-card { border-radius: 20px; border: 1px dashed var(--admin-border); transition: all 0.3s; background: var(--admin-bg); color: var(--admin-text) !important; }
    .quick-action-card:hover { background: var(--admin-card-bg); border-color: var(--primary-color); color: var(--primary-color) !important; }
    .system-logo-dash { max-height: 80px; width: auto; object-fit: contain; }
    .upsell-card { border-radius: 20px; border: 1px dashed var(--admin-border); background: var(--admin-bg); }

    /* Select2 Tweaks */
    .select2-container .select2-selection--single { height: 44px; display: flex; align-items: center; border-radius: 10px; border: 1px solid var(--admin-border); background-color: var(--admin-card-bg); }
    .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered { padding-left: 15px; font-size: 0.95rem; color: var(--admin-text); }
    .filter-bar { background: var(--admin-card-bg); padding: 20px; border-radius: 16px; border: 1px solid var(--admin-border); box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
    .btn-filter { height: 44px; border-radius: 10px; font-weight: 600; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row mb-5 animate-fade-in">
    <div class="col-12">
        <div class="card card-premium overflow-hidden border-0" style="background: var(--primary-gradient); min-height: 200px;">
            <div class="card-body p-5 d-flex align-items-center justify-content-between position-relative">
                <div class="text-white z-1">
                    <?php
                        $lightLogo = app_setting('style.logo_footer_url') ?: app_setting('style.logo_url');
                        if ($lightLogo):
                    ?>
                        <img src="<?= media_url($lightLogo) ?>" class="system-logo-dash mb-3" alt="Logo">
                        <div class="mb-3">
                            <a href="<?= site_url('/') ?>" target="_blank" class="btn btn-sm btn-light text-primary fw-bold rounded-pill px-3 py-2 shadow-sm" title="Abrir o site em uma nova aba">
                                <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Ir ao Site
                            </a>
                        </div>
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
                <a href="<?= site_url('checkout/plans') ?>" class="btn btn-<?= $subscriptionAlert['type'] ?> rounded-pill px-4 fw-bold">Ver Planos</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?= $this->include('Admin/partials/_dashboard_basico') ?>

<?php if ($painelCompleto): ?>
    <?= $this->include('Admin/partials/_dashboard_completo') ?>
<?php else: ?>
    <div class="row mb-5 animate-fade-in">
        <div class="col-12">
            <div class="card upsell-card border-0 p-4 p-lg-5 text-center">
                <i class="fa-solid fa-chart-line fa-2x text-primary mb-3"></i>
                <?php if (($nextPlanUpsell ?? null) !== null): ?>
                    <h5 class="fw-bold text-dark mb-2">Desbloqueie o plano <?= esc($nextPlanUpsell['plan_name']) ?></h5>
                    <p class="text-muted mb-3">
                        <?php if ($nextPlanUpsell['missing_features'] !== []): ?>
                            Você passa a ter <?= esc(implode(', ', $nextPlanUpsell['missing_features'])) ?>.
                        <?php endif; ?>
                        <?php if ($nextPlanUpsell['turbo_gain'] === 'ilimitadas'): ?>
                            Turbinadas mensais ilimitadas.
                        <?php elseif (is_int($nextPlanUpsell['turbo_gain']) && $nextPlanUpsell['turbo_gain'] > 0): ?>
                            +<?= $nextPlanUpsell['turbo_gain'] ?> turbinada(s) por mês.
                        <?php endif; ?>
                        <?php if ($nextPlanUpsell['credit_gain'] > 0): ?>
                            +R$ <?= number_format($nextPlanUpsell['credit_gain'], 2, ',', '.') ?> de crédito mensal em leads.
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <h5 class="fw-bold text-dark mb-2">Desbloqueie o painel completo</h5>
                    <p class="text-muted mb-4">
                        No plano Ouro ou Diamante você acompanha a evolução dos seus leads e visualizações no
                        tempo, de onde vêm seus acessos, e um comparativo de desempenho contra a média do
                        mercado — tudo no mesmo lugar.
                    </p>
                <?php endif; ?>
                <a href="<?= site_url('checkout/plans') ?>" class="btn btn-primary rounded-pill px-4 fw-bold mx-auto">
                    Conhecer os planos
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

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

<?= $this->endSection() ?>
