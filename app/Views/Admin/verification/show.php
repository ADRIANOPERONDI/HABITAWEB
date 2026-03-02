<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Revisar Identidade<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Revisar Verificação: <?= esc($account->nome) ?><?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container-fluid px-4">
    <div class="mb-4 mt-2">
        <a href="<?= site_url('admin/verification') ?>" class="btn btn-link text-decoration-none p-0 text-muted">
            <i class="fas fa-arrow-left me-1"></i> Voltar para a lista
        </a>
    </div>

    <div class="row g-4">
        <!-- Documentos -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Documentos Enviados</h6>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-6 text-center">
                            <label class="d-block mb-3 small fw-bold text-muted">RG/CNH (Frente)</label>
                            <?php if ($account->id_front): ?>
                                <a href="<?= base_url($account->id_front) ?>" target="_blank">
                                    <img src="<?= base_url($account->id_front) ?>" class="img-fluid rounded-4 shadow-sm border" style="max-height: 250px;">
                                </a>
                            <?php else: ?>
                                <div class="bg-light p-5 rounded-4 text-center">
                                    <i class="fas fa-image fa-3x opacity-25"></i>
                                    <p class="mt-2 mb-0 small text-muted">Não enviado</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 text-center">
                            <label class="d-block mb-3 small fw-bold text-muted">RG/CNH (Verso)</label>
                            <?php if ($account->id_back): ?>
                                <a href="<?= base_url($account->id_back) ?>" target="_blank">
                                    <img src="<?= base_url($account->id_back) ?>" class="img-fluid rounded-4 shadow-sm border" style="max-height: 250px;">
                                </a>
                            <?php else: ?>
                                <div class="bg-light p-5 rounded-4 text-center">
                                    <i class="fas fa-image fa-3x opacity-25"></i>
                                    <p class="mt-2 mb-0 small text-muted">Não enviado</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-lg-12 text-center mt-5">
                            <label class="d-block mb-3 small fw-bold text-muted">Selfie com Documento</label>
                            <?php if ($account->selfie): ?>
                                <a href="<?= base_url($account->selfie) ?>" target="_blank">
                                    <img src="<?= base_url($account->selfie) ?>" class="img-fluid rounded-4 shadow-sm border" style="max-height: 400px;">
                                </a>
                            <?php else: ?>
                                <div class="bg-light p-5 rounded-4 text-center">
                                    <i class="fas fa-user-circle fa-4x opacity-25"></i>
                                    <p class="mt-2 mb-0 small text-muted">Não enviado</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ação -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white py-3 text-center">
                    <h6 class="mb-0 fw-bold">Decisão de Verificação</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-4">
                        <label class="xsmall text-muted fw-bold text-uppercase d-block mb-1">Status Atual</label>
                        <?php if ($account->verification_status === 'APPROVED'): ?>
                            <span class="badge bg-success rounded-pill px-3 w-100 py-2">Aprovado</span>
                        <?php elseif ($account->verification_status === 'PENDING'): ?>
                            <span class="badge bg-warning text-dark rounded-pill px-3 w-100 py-2">Aguardando Revisão</span>
                        <?php elseif ($account->verification_status === 'REJECTED'): ?>
                            <span class="badge bg-danger rounded-pill px-3 w-100 py-2">Rejeitado</span>
                        <?php endif; ?>
                    </div>

                    <form action="<?= site_url('admin/verification/update/' . $account->id) ?>" method="post">
                        <?= csrf_field() ?>
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold small">Observações / Motivo da Rejeição</label>
                            <textarea name="notes" class="form-control" rows="4" placeholder="Ex: Foto do RG está ilegível. Por favor envie novamente."><?= esc($account->verification_notes) ?></textarea>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" name="action" value="APPROVE" class="btn btn-success py-3 fw-bold rounded-4 shadow-sm">
                                <i class="fas fa-check-circle me-1"></i> APROVAR IDENTIDADE
                            </button>
                            <button type="submit" name="action" value="REJECT" class="btn btn-outline-danger py-3 fw-bold rounded-4">
                                <i class="fas fa-times-circle me-1"></i> REJEITAR DOCUMENTOS
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 bg-light p-4 rounded-4 shadow-sm">
                <h6 class="fw-bold mb-3"><i class="fas fa-info-circle text-primary me-2"></i> O que isso faz?</h6>
                <ul class="small mb-0 ps-3">
                    <li><strong>Aprovar:</strong> Libera imediatamente a criação e edição de imóveis para esta conta.</li>
                    <li><strong>Rejeitar:</strong> Mantém o bloqueio e notifica o usuário via dashboard com o seu motivo.</li>
                </ul>
            </div>
        </div>

        <!-- BIOMETRIC PROOF (NEW) -->
        <?php if ($account->liveness_data): ?>
            <div class="col-lg-12 mt-4">
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="mb-0 fw-bold"><i class="fas fa-fingerprint text-primary me-2"></i> Prova Biométrica (Liveness Check)</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <?php 
                            $liveness = json_decode($account->liveness_data);
                            $labels = ['Frente', 'Direita', 'Esquerda', 'Cima', 'Baixo'];
                            foreach ($liveness as $index => $path): 
                            ?>
                                <div class="col-md">
                                    <div class="bg-light rounded-4 overflow-hidden position-relative">
                                        <div class="position-absolute bottom-0 start-0 w-100 bg-dark bg-opacity-50 text-white text-center py-1 xsmall">
                                            <?= $labels[$index] ?? "Frame $index" ?>
                                        </div>
                                        <img src="<?= base_url($path) ?>" class="img-fluid w-100" style="height: 120px; object-fit: cover; cursor: pointer;" onclick="window.open(this.src)">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
