<?= $this->extend('Layouts/public') ?>

<?= $this->section('title') ?>Resultados da Busca<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container py-5">
    <div class="row">
        <!-- Sidebar Filters -->
        <div class="col-lg-3 mb-4">
            <div class="card border-0 shadow-sm p-4 sticky-top" style="top: 100px; z-index: 100;">
                <h5 class="fw-bold mb-4"><i class="fa-solid fa-filter text-primary me-2"></i> Filtros</h5>
                <form action="<?= site_url('imoveis') ?>" method="GET">
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Negócio</label>
                        <select name="tipo_negocio" class="form-select select2-public">
                            <option value="">Todos</option>
                            <option value="VENDA" <?= ($filters['tipo_negocio'] ?? '') == 'VENDA' ? 'selected' : '' ?>>Comprar</option>
                            <option value="ALUGUEL" <?= ($filters['tipo_negocio'] ?? '') == 'ALUGUEL' ? 'selected' : '' ?>>Alugar</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Cidade</label>
                        <input type="text" name="cidade" class="form-control" placeholder="Ex: São Paulo" value="<?= esc($filters['cidade'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Bairro</label>
                        <input type="text" name="bairro" class="form-control" placeholder="Ex: Centro" value="<?= esc($filters['bairro'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Tipo</label>
                        <select name="tipo_imovel" class="form-select select2-public">
                            <option value="">Todos</option>
                            <option value="APARTAMENTO" <?= ($filters['tipo_imovel'] ?? '') == 'APARTAMENTO' ? 'selected' : '' ?>>Apartamento</option>
                            <option value="CASA" <?= ($filters['tipo_imovel'] ?? '') == 'CASA' ? 'selected' : '' ?>>Casa</option>
                            <option value="TERRENO" <?= ($filters['tipo_imovel'] ?? '') == 'TERRENO' ? 'selected' : '' ?>>Terreno</option>
                            <option value="COMERCIAL" <?= ($filters['tipo_imovel'] ?? '') == 'COMERCIAL' ? 'selected' : '' ?>>Comercial</option>
                        </select>
                    </div>
                    
                </form>

                <div class="mt-4 pt-4 border-top">
                    <h6 class="fw-bold mb-2 small"><i class="fa-solid fa-bell text-warning me-2"></i> Quer receber novidades?</h6>
                    <p class="text-muted x-small mb-3">Receba novos imóveis com esses mesmos filtros direto no seu e-mail.</p>
                    <button type="button" class="btn btn-outline-warning btn-sm w-100 fw-bold" data-bs-toggle="modal" data-bs-target="#alertModal">
                        Criar Alerta de Busca
                    </button>
                </div>
            </div>
        </div>

        <!-- Results -->
        <div class="col-lg-9">
            
            <?php if(!empty($promotedProperties)): ?>
            <!-- Promoted Carousel -->
            <div class="mb-5">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-fire text-danger me-2"></i> Destaques da Busca</h5>
                    <span class="badge bg-light text-muted border">Patrocinados</span>
                </div>
                
                <div class="d-flex gap-3 overflow-auto pb-3 custom-scrollbar" style="scroll-snap-type: x mandatory;">
                    <?php foreach($promotedProperties as $prop): ?>
                        <div class="card property-card shadow-sm border-0 flex-shrink-0" style="width: 280px; scroll-snap-align: start;">
                            <a href="<?= site_url('imovel/' . $prop->id) ?>" class="text-decoration-none text-dark h-100 d-flex flex-column">
                                <div class="position-relative" style="height: 180px; overflow: hidden; border-radius: 0.5rem 0.5rem 0 0;">
                                    <!-- Badges -->
                                    <div class="position-absolute top-0 start-0 m-2 z-2 d-flex flex-column gap-1">
                                        <?php if($prop->is_destaque || (isset($prop->highlight_level) && $prop->highlight_level > 0)): ?>
                                            <span class="badge bg-warning text-dark border shadow-sm" style="font-size: 0.65rem;"><i class="fa-solid fa-crown"></i> PATROCINADO</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <img src="<?= !empty($prop->cover_image) ? (strpos($prop->cover_image, 'http') === 0 ? $prop->cover_image : base_url($prop->cover_image)) : base_url('assets/img/placeholder-house.png') ?>" 
                                         class="w-100 h-100 object-fit-cover" 
                                         alt="<?= esc($prop->titulo) ?>"
                                         onerror="this.src='<?= base_url('assets/img/placeholder-house.png') ?>'">
                                </div>
                                <div class="card-body p-3 d-flex flex-column">
                                    <h6 class="fw-bold mb-1 text-truncate"><?= esc($prop->bairro) ?></h6>
                                    <p class="small text-muted mb-2 text-truncate"><?= esc($prop->titulo) ?></p>
                                    <div class="mt-auto fw-bold text-primary">
                                        R$ <?= number_format($prop->preco, 2, ',', '.') ?>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <h4 class="fw-bold mb-4">Encontrados <?= count($properties) ?> imóveis</h4>
            
            <?php if(empty($properties)): ?>
                <div class="text-center py-5">
                    <i class="fa-solid fa-search fa-3x text-muted mb-3 opacity-25"></i>
                    <h5 class="text-muted">Nenhum imóvel encontrado.</h5>
                    <p class="text-muted small">Tente remover alguns filtros para ver mais resultados.</p>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach($properties as $property): ?>
                    <div class="col-md-6">
                        <div class="card property-card h-100 bg-white shadow-sm border-0">
                            <a href="<?= site_url('imovel/' . $property->id) ?>" class="text-decoration-none d-flex flex-column h-100">
                                <div class="card-img-top-wrapper position-relative" style="height: 220px; overflow: hidden;">
                                    <div class="position-absolute top-0 start-0 m-3 z-3 d-flex flex-column gap-2">
                                        <span class="badge bg-white text-dark shadow-sm rounded-pill px-3 py-2 fw-bold">
                                            <?= $property->tipo_negocio === 'VENDA' ? 'Venda' : 'Aluguel' ?>
                                        </span>
                                        <?php if($property->is_destaque || (isset($property->highlight_level) && $property->highlight_level > 0)): ?>
                                            <span class="badge bg-warning text-dark shadow-sm rounded-pill px-3 py-2 fw-bold"><i class="fa-solid fa-certificate me-1"></i> Patrocinado</span>
                                        <?php endif; ?>
                                        <?php if($property->is_novo && !$property->is_destaque): ?>
                                            <span class="badge bg-success text-white shadow-sm rounded-pill px-3 py-2 fw-bold">Novo</span>
                                        <?php endif; ?>
                                        <?php if($property->is_exclusivo): ?>
                                            <span class="badge bg-primary text-white shadow-sm rounded-pill px-3 py-2 fw-bold" style="background-color: #6f42c1 !important;">
                                                <i class="fa-solid fa-shield-halved me-1"></i> Exclusivo
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if(!empty($property->cover_image)): ?>
                                        <img src="<?= (strpos($property->cover_image, 'http') === 0 ? $property->cover_image : base_url($property->cover_image)) ?>" class="card-img-top w-100 h-100" style="object-fit: cover;" alt="<?= esc($property->titulo) ?>" onerror="this.src='<?= base_url('assets/img/placeholder-house.png') ?>'">
                                    <?php else: ?>
                                        <img src="<?= base_url('assets/img/placeholder-house.png') ?>" class="card-img-top w-100 h-100" style="object-fit: cover;" alt="Sem Foto">
                                    <?php endif; ?>
                                </div>
                                <div class="card-body p-4 d-flex flex-column flex-grow-1 text-start">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <h6 class="fw-bold mb-0 text-dark text-truncate w-75"><?= esc($property->bairro) ?>, <?= esc($property->cidade) ?></h6>
                                        <?php if(isset($property->highlight_level) && $property->highlight_level > 0): ?>
                                            <small class="badge bg-secondary text-white"><i class="fa-solid fa-arrow-up me-1"></i> Impulsionado</small>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-muted x-small mb-3 text-truncate"><?= esc($property->titulo) ?></p>

                                    <div class="property-specs d-flex gap-3 mb-3 text-muted x-small border-bottom pb-2">
                                        <?php if($property->area_total): ?>
                                            <span><i class="fa-solid fa-maximize me-1"></i> <?= (int)$property->area_total ?>m²</span>
                                        <?php endif; ?>
                                        <?php if($property->quartos): ?>
                                            <span><i class="fa-solid fa-bed me-1"></i> <?= $property->quartos ?></span>
                                        <?php endif; ?>
                                        <?php if($property->banheiros): ?>
                                            <span><i class="fa-solid fa-bath me-1"></i> <?= $property->banheiros ?></span>
                                        <?php endif; ?>
                                        <?php if($property->vagas): ?>
                                            <span><i class="fa-solid fa-car me-1"></i> <?= $property->vagas ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="d-flex align-items-center justify-content-between mt-auto">
                                        <div class="fw-bold fs-5 text-dark">R$ <?= number_format($property->preco, 2, ',', '.') ?></div>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if($property->account_logo): ?>
                                                <img src="<?= base_url($property->account_logo) ?>" class="rounded-circle border" width="24" height="24" alt="Logo">
                                            <?php endif; ?>
                                            <span class="x-small text-muted fw-bold"><?= esc($property->account_name) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="mt-5 d-flex justify-content-center">
                    <?= $pager->links() ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<!-- Modal Criar Alerta -->
<div class="modal fade" id="alertModal" tabindex="-1" aria-labelledby="alertModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="alertModalLabel">🔔 Criar Alerta de Busca</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="propertyAlertForm">
                <div class="modal-body p-4">
                    <p class="text-muted small mb-4">Sempre que entrar um novo imóvel compatível com sua busca atual, enviaremos um e-mail para você.</p>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Seu E-mail</label>
                        <input type="email" name="email" class="form-control" placeholder="exemplo@email.com" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Frequência</label>
                        <select name="frequencia" class="form-select">
                            <option value="IMEDIATO">Imediato (Assim que cadastrado)</option>
                            <option value="DIARIO" selected>Resumo Diário</option>
                            <option value="SEMANAL">Resumo Semanal</option>
                        </select>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="lgpdConsent" required>
                        <label class="form-check-label x-small text-muted" for="lgpdConsent">
                            Concordo em receber notificações de imóveis conforme a Política de Privacidade (LGPD).
                        </label>
                    </div>

                    <div id="alertFeedback" class="mt-3 text-center d-none"></div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" id="btnSubmitAlert" class="btn btn-primary rounded-pill px-4 fw-bold">Salvar Alerta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('propertyAlertForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const btn = document.getElementById('btnSubmitAlert');
    const feedback = document.getElementById('alertFeedback');
    const originalText = btn.innerHTML;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Salvando...';
    feedback.classList.add('d-none');
    
    // Captura os filtros atuais da URL
    const urlParams = new URLSearchParams(window.location.search);
    const filtros = {};
    for (const [key, value] of urlParams.entries()) {
        if(value && key !== 'p') filtros[key] = value; // Ignora paginação
    }

    const formData = new FormData(this);
    formData.append('filtros', JSON.stringify(filtros));

    fetch('<?= site_url('alertas/criar') ?>', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        feedback.classList.remove('d-none');
        if(data.success) {
            feedback.className = 'mt-3 text-center text-success fw-bold';
            feedback.innerHTML = '<i class="fa-solid fa-check-circle"></i> ' + data.message;
            setTimeout(() => {
                const bModal = bootstrap.Modal.getInstance(document.getElementById('alertModal'));
                if(bModal) bModal.hide();
                this.reset();
                feedback.classList.add('d-none');
            }, 3000);
        } else {
            feedback.className = 'mt-3 text-center text-danger small';
            feedback.innerHTML = data.message || 'Erro ao salvar alerta.';
        }
    })
    .catch(error => {
        feedback.classList.remove('d-none');
        feedback.className = 'mt-3 text-center text-danger small';
        feedback.innerHTML = 'Erro de conexão.';
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
});
</script>
