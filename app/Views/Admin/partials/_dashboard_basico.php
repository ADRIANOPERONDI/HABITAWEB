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
