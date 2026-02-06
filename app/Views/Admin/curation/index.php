<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Curadoria e Qualidade<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Curadoria e Qualidade<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .card-premium { border: none; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    .badge-soft { padding: 0.5em 1em; border-radius: 50px; font-weight: 600; font-size: 0.75rem; }
    .bg-warning-soft { background-color: rgba(255, 193, 7, 0.1); color: #856404; }
    .bg-danger-soft { background-color: rgba(220, 53, 69, 0.1); color: #842029; }
    .bg-success-soft { background-color: rgba(25, 135, 84, 0.1); color: #0f5132; }
    .bg-primary-soft { background-color: rgba(99, 102, 241, 0.1); color: #6366f1; }
    .table-premium thead { background-color: #f8f9fa; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.5px; }
    .avatar-circle { width: 35px; height: 35px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="row">
    <!-- Queue: Flagged Properties -->
    <div class="col-12 mb-5">
        <div class="card card-premium animate-fade-in">
            <div class="card-header bg-white py-3 border-0 d-flex align-items-center justify-content-between">
                <h5 class="m-0 fw-bold text-dark"><i class="fa-solid fa-shield-halved text-warning me-2"></i> Revisão de Qualidade</h5>
                <span class="badge bg-warning-soft text-warning rounded-pill px-3">Automoderação</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($flagged_properties)): ?>
                    <div class="p-5 text-center text-muted">
                        <i class="fa-solid fa-circle-check fa-3x mb-3 opacity-25"></i>
                        <p class="mb-0">Tudo limpo por aqui! Nenhum imóvel pendente de revisão.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 table-premium">
                            <thead>
                                <tr>
                                    <th class="ps-4">Imóvel</th>
                                    <th>Alertas de Qualidade</th>
                                    <th class="text-center">Score</th>
                                    <th class="text-end pe-4">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($flagged_properties as $prop): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center">
                                                <div class="bg-light rounded-3 p-2 me-3">
                                                    <i class="fa-solid fa-house-circle-exclamation text-warning"></i>
                                                </div>
                                                <div>
                                                    <a href="<?= base_url('admin/properties/' . $prop->id . '/edit') ?>" class="fw-bold text-dark text-decoration-none">
                                                        <?= esc($prop->titulo) ?>
                                                    </a>
                                                    <div class="small text-muted">ID: #<?= $prop->id ?> • R$ <?= number_format($prop->preco, 2, ',', '.') ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                                $warnings = $prop->quality_warnings ?? [];
                                                if (is_string($warnings)) $warnings = json_decode($warnings, true) ?? [];
                                                foreach (array_slice($warnings, 0, 3) as $w): ?>
                                                    <span class="badge bg-warning-soft text-warning rounded-pill small me-1"><?= esc($w) ?></span>
                                            <?php endforeach; ?>
                                            <?php if(count($warnings) > 3): ?>
                                                <span class="text-muted small">+<?= count($warnings)-3 ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="fw-bold text-<?= $prop->score_qualidade < 50 ? 'danger' : ($prop->score_qualidade < 80 ? 'warning' : 'success') ?>">
                                                <?= $prop->score_qualidade ?> pts
                                            </div>
                                        </td>
                                        <td class="text-end pe-4">
                                            <button type="button" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm"
                                                    onclick="confirmAction('<?= base_url('admin/curation/approve/' . $prop->id) ?>', 'POST', 'Aprovar Imóvel?', 'O imóvel voltará a ser exibido normalmente.')">
                                                <i class="fas fa-check me-1"></i> Aprovar
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Reports Section -->
    <div class="col-12">
        <div class="card card-premium animate-fade-in" style="animation-delay: 0.1s">
            <div class="card-header bg-white py-4 border-0 d-flex align-items-center justify-content-between">
                <div>
                    <h5 class="m-0 fw-bold text-dark"><i class="fa-solid fa-flag text-danger me-2"></i> Denúncias de Usuários</h5>
                    <p class="text-muted small mb-0 mt-1">Monitore e resolva conflitos reportados pela comunidade.</p>
                </div>
                
                <div class="dropdown">
                    <button class="btn btn-light border dropdown-toggle rounded-pill px-4" type="button" data-bs-toggle="dropdown">
                        <?php 
                        $filterStatusTranslation = match($filter_status) {
                            'PENDING'  => 'Pendentes',
                            'RESOLVED' => 'Resolvidas',
                            'REJECTED' => 'Rejeitadas',
                            default    => $filter_status
                        };
                        ?>
                        <i class="fa-solid fa-filter me-2 text-muted"></i> Filtrar: <strong><?= esc($filterStatusTranslation) ?></strong>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-2">
                        <li><a class="dropdown-item rounded-3 py-2 <?= $filter_status == 'PENDING' ? 'active' : '' ?>" href="?status=PENDING">Pendentes</a></li>
                        <li><a class="dropdown-item rounded-3 py-1 <?= $filter_status == 'RESOLVED' ? 'active' : '' ?>" href="?status=RESOLVED">Resolvidas</a></li>
                        <li><a class="dropdown-item rounded-3 py-2 <?= $filter_status == 'REJECTED' ? 'active' : '' ?>" href="?status=REJECTED">Rejeitadas</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="card-body p-0">
                <?php if (empty($reports)): ?>
                    <div class="p-5 text-center text-muted">
                        <i class="fa-solid fa-inbox fa-3x mb-3 opacity-25"></i>
                        <p class="mb-0">Nenhuma denúncia encontrada para este filtro.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 table-premium">
                            <thead>
                                <tr>
                                    <th class="ps-4">Data & Motivo</th>
                                    <th>Imóvel Denunciado</th>
                                    <th>Denunciante</th>
                                    <th class="text-end pe-4">Decisão</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $report): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold mb-0"><?= esc($report['reason']) ?></div>
                                            <div class="small text-muted"><?= date('d/m/Y • H:i', strtotime($report['created_at'])) ?></div>
                                            <span class="badge bg-danger-soft text-danger rounded-pill x-small"><?= esc($report['type']) ?></span>
                                        </td>
                                        <td>
                                            <a href="<?= base_url('admin/properties/' . $report['property_id'] . '/edit') ?>" class="text-dark text-decoration-none fw-bold small d-block">
                                                #<?= $report['property_id'] ?> - <?= esc($report['titulo']) ?>
                                            </a>
                                            <span class="badge bg-<?= $report['prop_status'] == 'ACTIVE' ? 'success' : 'light' ?>-soft text-<?= $report['prop_status'] == 'ACTIVE' ? 'success' : 'muted' ?> x-small mt-1">
                                                Status: <?= match($report['prop_status']) {
                                                    'ACTIVE' => 'Ativo',
                                                    'PAUSED' => 'Pausado',
                                                    'PENDING' => 'Pendente',
                                                    'SOLD' => 'Vendido',
                                                    default => $report['prop_status']
                                                } ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-circle bg-primary-soft text-primary small me-2">
                                                    <?= strtoupper(substr($report['reporter_email'] ?? 'H', 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <div class="small fw-bold lh-1"><?= $report['reporter_email'] ?? 'Visitante' ?></div>
                                                    <small class="text-muted x-small"><?= $report['ip_address'] ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-end pe-4">
                                            <?php if ($report['status'] === 'PENDING'): ?>
                                                <div class="d-flex gap-2 justify-content-end">
                                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3"
                                                            onclick="confirmAction('<?= base_url('admin/curation/resolve/' . $report['id']) ?>', 'POST', 'Pausar Imóvel?', 'Esta ação acatará a denúncia e pausará o anúncio.', {action: 'PAUSE_PROPERTY'})">
                                                        <i class="fa-solid fa-ban me-1"></i> Pausar
                                                    </button>

                                                    <button type="button" class="btn btn-sm btn-light border rounded-pill px-3"
                                                            onclick="confirmAction('<?= base_url('admin/curation/resolve/' . $report['id']) ?>', 'POST', 'Ignorar Denúncia?', 'Esta ação rejeitará a denúncia realizada.', {action: 'DISMISS'})">
                                                        Ignorar
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <?php 
                                                $statusTranslation = match($report['status']) {
                                                    'RESOLVED' => 'Resolvida',
                                                    'REJECTED' => 'Rejeitada',
                                                    default => $report['status']
                                                };
                                                ?>
                                                <span class="badge bg-<?= $report['status'] == 'RESOLVED' ? 'success' : 'secondary' ?>-soft text-<?= $report['status'] == 'RESOLVED' ? 'success' : 'muted' ?> rounded-pill px-3">
                                                    <?= esc($statusTranslation) ?>
                                                </span>
                                                <?php if($report['resolution_notes']): ?>
                                                    <div class="x-small text-muted text-truncate mt-1" style="max-width: 150px;"><?= esc($report['resolution_notes']) ?></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($pager && $pager->getPageCount() > 1): ?>
                <div class="card-footer bg-white border-0 py-4 d-flex justify-content-center">
                    <?= $pager->links() ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

