<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Meus Imóveis<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Imóveis Cadastrados<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .property-card-airbnb { border: none; border-radius: 16px; overflow: hidden; background: #fff; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); cursor: pointer; position: relative; height: 100%; border: 1px solid #f0f0f0; }
    .property-card-airbnb:hover { transform: translateY(-8px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.08); }
    .property-image-wrapper { position: relative; padding-top: 75%; overflow: hidden; }
    .property-image-wrapper img { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease; }
    .property-card-airbnb:hover .property-image-wrapper img { transform: scale(1.1); }
    .property-status-badge { position: absolute; top: 12px; left: 12px; z-index: 10; padding: 6px 12px; border-radius: 30px; font-weight: 700; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    .property-price { font-size: 1.1rem; font-weight: 800; color: #344767; }
    .property-actions-floating { position: absolute; top: 12px; right: 12px; z-index: 10; display: flex; flex-direction: column; gap: 8px; opacity: 0; transition: opacity 0.3s; }
    .property-card-airbnb:hover .property-actions-floating { opacity: 1; }
    .btn-action-float { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.9); border: none; color: #344767; box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-decoration: none !important; }
    .btn-action-float:hover { background: #fff; color: var(--bs-primary); transform: scale(1.1); text-decoration: none !important; }
    .filter-bar-airbnb { background: #fff; border-radius: 50px; padding: 10px 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #f0f0f0; margin-bottom: 2rem; }
    .filter-input { border: none !important; background: transparent !important; box-shadow: none !important; }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<!-- Top Actions & Filter -->
<div class="row align-items-center mb-4 g-3">
    <div class="col-lg-8">
        <form action="<?= current_url() ?>" method="get" class="filter-bar-airbnb d-flex align-items-center gap-2">
            <div class="flex-grow-1 border-end pe-3">
                <div class="d-flex align-items-center">
                    <i class="fa-solid fa-magnifying-glass text-muted me-2"></i>
                    <input type="text" name="term" class="form-control filter-input" placeholder="Buscar por título ou cidade..." value="<?= esc($filters['term'] ?? '') ?>">
                </div>
            </div>
            
            <?php if (!empty($accounts)): ?>
            <div class="px-3 border-end">
                <select name="account_id" class="form-select filter-input fw-bold" style="width: auto; max-width: 200px;">
                    <option value="">Todas as Contas</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?= $acc->id ?>" <?= ($filters['account_id'] ?? '') == $acc->id ? 'selected' : '' ?>>
                            <?= esc($acc->nome) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="px-3">
                <select name="status" class="form-select filter-input fw-bold" style="width: auto;">
                    <option value="ALL">Status</option>
                    <option value="ACTIVE" <?= ($filters['status'] ?? '') == 'ACTIVE' ? 'selected' : '' ?>>Ativos</option>
                    <option value="DRAFT" <?= ($filters['status'] ?? '') == 'DRAFT' ? 'selected' : '' ?>>Rascunhos</option>
                    <option value="PAUSED" <?= ($filters['status'] ?? '') == 'PAUSED' ? 'selected' : '' ?>>Pausados</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary rounded-circle p-2 px-3">
                <i class="fa-solid fa-arrow-right"></i>
            </button>
        </form>
    </div>
    <div class="col-lg-4 text-lg-end">
        <a href="<?= site_url('admin/properties/new') ?>" class="btn btn-primary btn-lg rounded-pill px-4 shadow">
            <i class="fa-solid fa-plus me-2"></i> Anunciar Imóvel
        </a>
    </div>
</div>

<!-- Tabs & Actions -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <ul class="nav nav-pills nav-pills-premium bg-white p-1 rounded-pill shadow-sm">
        <li class="nav-item">
            <a class="nav-link rounded-pill px-4 <?= ($currentView === 'active') ? 'active' : '' ?>" href="<?= site_url('admin/properties?view=active') ?>">
                <i class="fa-solid fa-house-circle-check me-2"></i> Ativos
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill px-4 <?= ($currentView === 'deleted') ? 'active' : '' ?>" href="<?= site_url('admin/properties?view=deleted') ?>">
                <i class="fa-solid fa-house-circle-xmark me-2"></i> Inativos
            </a>
        </li>
    </ul>
</div>

<!-- Property Grid -->
<div class="row g-4">
    <?php if (empty($properties)): ?>
        <div class="col-12 py-5 text-center">
            <div class="py-5">
                <i class="fa-solid fa-cloud-moon fa-4x text-light mb-4"></i>
                <h3 class="fw-bold text-muted">Nenhum imóvel <?= $currentView === 'deleted' ? 'inativo' : 'aqui' ?>...</h3>
                <?php if($currentView === 'active'): ?>
                    <p class="text-muted">Comece cadastrando seu primeiro imóvel no portal.</p>
                    <a href="<?= site_url('admin/properties/new') ?>" class="btn btn-primary px-5 rounded-pill mt-3">Anunciar Agora</a>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($properties as $property): ?>
            <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                <div class="property-card-airbnb">
                    <!-- Status Badge -->
                    <?php
                        $statusData = match($property->status) {
                            'ACTIVE' => ['class' => 'bg-success text-white', 'label' => 'Ativo'],
                            'DRAFT'  => ['class' => 'bg-secondary text-white', 'label' => 'Rascunho'],
                            'PAUSED' => ['class' => 'bg-warning text-dark', 'label' => 'Pausado'],
                            'SOLD'   => ['class' => 'bg-primary text-white', 'label' => 'Vendido'],
                            default  => ['class' => 'bg-light text-muted', 'label' => 'Indisponível']
                        };
                    ?>
                    <div class="property-status-badge <?= $statusData['class'] ?>">
                        <?= $statusData['label'] ?>
                    </div>

                    <!-- Highlight Badges -->
                    <div style="position: absolute; top: 12px; left: auto; right: 60px; z-index: 10; display: flex; gap: 5px;">
                        <?php if($property->is_destaque): ?>
                            <span class="badge bg-warning text-dark border shadow-sm" style="font-size: 0.7rem;">
                                <i class="fa-solid fa-star"></i> DESTAQUE
                            </span>
                        <?php endif; ?>
                        
                        <?php if(isset($property->highlight_level) && $property->highlight_level > 0): ?>
                             <?php
                                $turboLabel = match($property->highlight_level) {
                                    1 => 'TURBO',
                                    2 => 'SUPER TURBO',
                                    3 => 'MEGA TURBO',
                                    default => 'TURBO'
                                };
                             ?>
                            <span class="badge bg-primary text-white border shadow-sm" style="font-size: 0.7rem;">
                                <i class="fa-solid fa-rocket"></i> <?= $turboLabel ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Floating Actions -->
                    <div class="property-actions-floating">
                        <?php if($currentView === 'active'): ?>
                            <!-- Visualizar -->
                            <a href="<?= site_url('imovel/' . $property->id) ?>" target="_blank" onclick="event.stopPropagation()" class="btn-action-float text-primary" title="Ver no Site">
                                <i class="fa-solid fa-eye"></i>
                            </a>

                            <!-- Destacar (Plano) -->
                            <?php 
                            // Show ONLY if NOT highlighted AND allowed
                            $isHighlighted = $property->is_destaque;
                            $canHighlight = $destaqueStats['allowed'] ?? false;
                            
                            if (!$isHighlighted && $canHighlight): 
                            ?>
                            <button type="button" class="btn-action-float text-secondary" 
                                    title="Destacar com Plano"
                                    onclick="toggleDestaque(<?= $property->id ?>)">
                                <i class="fa-regular fa-star"></i>
                            </button>
                            <?php endif; ?>

                            <!-- Turbinar (Turbo) -->
                            <?php if (($property->highlight_level ?? 0) == 0): ?>
                            <a href="<?= site_url('admin/properties/' . $property->id . '/turbo') ?>" onclick="event.stopPropagation()" class="btn-action-float text-warning" title="Turbinar Anúncio">
                                <i class="fa-solid fa-rocket"></i>
                            </a>
                            <?php endif; ?>

                            <a href="<?= site_url('admin/properties/' . $property->id . '/edit') ?>" onclick="event.stopPropagation()" class="btn-action-float" title="Editar">
                                <i class="fa-solid fa-pen"></i>
                            </a>

                            <!-- Botão Encerrar (Vendido/Alugado) -->
                            <button type="button" class="btn-action-float text-success" title="Encerrar Anúncio" 
                                    onclick="openClosureModal(<?= $property->id ?>, '<?= esc($property->titulo) ?>')">
                                <i class="fa-solid fa-check-double"></i>
                            </button>

                            <!-- Botão Desativar (Soft Delete) -->
                            <button type="button" class="btn-action-float text-danger" title="Desativar" 
                                    onclick="confirmAction('<?= site_url('admin/properties/' . $property->id) ?>', 'DELETE', 'Deseja realmente desativar este anúncio? Ele sairá do ar imediatamente.')">
                                <i class="fa-solid fa-eye-slash"></i>
                            </button>
                        <?php else: ?>
                            <!-- Ações para Inativos -->
                            <button type="button" class="btn-action-float text-success" title="Restaurar Imóvel"
                                    onclick="confirmAction('<?= site_url('admin/properties/' . $property->id . '/restore') ?>', 'POST', 'Deseja restaurar este imóvel?')">
                                <i class="fa-solid fa-rotate-left"></i>
                            </button>
                        <?php endif; ?>
                    </div>

                    <!-- Image Section -->
                    <div class="property-image-wrapper">
                        <?php 
                            // Tenta imagem do imóvel, senão usa placeholder
                            $imgUrl = !empty($property->cover_image) ? (strpos($property->cover_image, 'http') === 0 ? $property->cover_image : base_url($property->cover_image)) : base_url('assets/img/placeholder-house.png');
                        ?>
                        <img src="<?= $imgUrl ?>" alt="<?= esc($property->titulo) ?>" onerror="this.src='<?= base_url('assets/img/placeholder-house.png') ?>'">
                    </div>

                    <!-- Content Section -->
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <h6 class="fw-bold text-dark text-truncate mb-0" style="max-width: 80%"><?= esc($property->titulo) ?></h6>
                            <div class="small fw-bold"><i class="fa-solid fa-star text-warning"></i> Novo</div>
                        </div>
                        <p class="text-muted small mb-2 text-truncate"><?= esc($property->bairro) ?>, <?= esc($property->cidade) ?></p>
                        
                        <div class="d-flex gap-2 mb-3">
                            <span class="badge bg-light text-dark border-0 small"><i class="fa-solid fa-bed me-1"></i> <?= $property->quartos ?? 0 ?></span>
                            <span class="badge bg-light text-dark border-0 small"><i class="fa-solid fa-bath me-1"></i> <?= $property->banheiros ?? 0 ?></span>
                            <span class="badge bg-light text-dark border-0 small"><i class="fa-solid fa-maximize me-1"></i> <?= $property->area_util ?? $property->area_total ?? 0 ?>m²</span>
                        </div>

                        <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-1">
                            <div class="property-price text-primary">
                                R$ <?= number_format($property->preco, 2, ',', '.') ?>
                                <?php if($property->tipo_negocio == 'ALUGUEL'): ?><span class="fs-7 text-muted fw-normal">/mês</span><?php endif; ?>
                            </div>
                            <div class="fs-7 text-muted border-start ps-2">
                                <i class="fa-regular fa-calendar-alt me-1"></i> <?= date('d/m', strtotime($property->created_at)) ?>
                            </div>
                        </div>
                        
                        <?php if (auth()->user()->inGroup('superadmin', 'admin') && isset($property->account_name)): ?>
                        <div class="mt-3 p-2 bg-light rounded text-center small border">
                            <i class="fa-solid fa-briefcase text-muted me-1"></i> 
                            Anunciante: <strong><?= esc($property->account_name) ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="d-flex justify-content-center mt-5">
    <?= $pager->links('default', 'bootstrap_full') ?>
</div>

<?= $this->endSection() ?>

<!-- Modal de Encerramento (Vendido/Alugado) -->
<div class="modal fade" id="closureModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-0 pb-0 shadow-sm p-4">
                <h5 class="fw-bold mb-0"><i class="fa-solid fa-trophy text-warning me-2"></i> Encerrar Anúncio</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small mb-4">Parabéns pelo negócio! Selecione o motivo do encerramento para o imóvel: <br><strong id="closurePropertyName" class="text-dark"></strong>.</p>
                
                <form id="closureForm">
                    <input type="hidden" id="closurePropertyId" name="property_id">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase">Motivo do Encerramento</label>
                        <select name="reason" id="closureReason" class="form-select rounded-pill px-3" required>
                            <option value="">Selecione um motivo...</option>
                            <option value="VENDIDO">Imóvel Vendido</option>
                            <option value="ALUGADO">Imóvel Alugado</option>
                            <option value="DESISTENCIA">Desistência do Proprietário</option>
                            <option value="ERRO">Erro de Cadastro / Duplicidade</option>
                            <option value="OUTRO">Outros</option>
                        </select>
                    </div>

                    <div id="leadLinkSection" class="mb-3 d-none">
                        <label class="form-label fw-bold small text-uppercase">Este negócio veio de um Lead?</label>
                        <select name="lead_id" id="closureLeadId" class="form-select rounded-pill px-3">
                            <option value="">Não (Venda Externa / Outra Origem)</option>
                            <!-- AJAX loaded leads -->
                        </select>
                        <div class="form-text small">Vincular o lead ajuda a medir o ROI do portal.</div>
                    </div>

                    <div id="valueSection" class="mb-3 d-none">
                        <label class="form-label fw-bold small text-uppercase">Valor Final (Opcional)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 rounded-start-pill">R$</span>
                            <input type="number" step="0.01" name="closing_value" class="form-control border-start-0 rounded-end-pill" placeholder="0,00">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase">Observações (Opcional)</label>
                        <textarea name="closing_notes" class="form-control" rows="2" style="border-radius: 15px;" placeholder="Alguma nota sobre o fechamento?"></textarea>
                    </div>

                    <div class="d-grid mt-4">
                        <button type="submit" class="btn btn-primary btn-lg rounded-pill shadow-sm">
                            <i class="fa-solid fa-check-circle me-2"></i> Confirmar Encerramento
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?= $this->section('scripts') ?>
<script>
    function openClosureModal(id, title) {
        $('#closurePropertyId').val(id);
        $('#closurePropertyName').text(title);
        $('#closureForm')[0].reset();
        $('#leadLinkSection').addClass('d-none');
        $('#valueSection').addClass('d-none');
        
        // Carrega leads do imóvel
        $.get(`<?= site_url('admin/properties') ?>/${id}/closure-leads`, function(leads) {
            let options = '<option value="">Não (Venda Externa / Outra Origem)</option>';
            if (leads && leads.length > 0) {
                leads.forEach(l => {
                    options += `<option value="${l.id}">${l.nome_visitante} (${new Date(l.created_at).toLocaleDateString()})</option>`;
                });
            }
            $('#closureLeadId').html(options);
            
            const modal = new bootstrap.Modal(document.getElementById('closureModal'));
            modal.show();
        }).fail(function() {
            Swal.fire('Erro', 'Não foi possível carregar os leads para este imóvel.', 'error');
        });
    }

    $('#closureReason').on('change', function() {
        const val = $(this).val();
        if (val === 'VENDIDO' || val === 'ALUGADO') {
            $('#leadLinkSection').removeClass('d-none');
            $('#valueSection').removeClass('d-none');
        } else {
            $('#leadLinkSection').addClass('d-none');
            $('#valueSection').addClass('d-none');
        }
    });

    $('#closureForm').on('submit', function(e) {
        e.preventDefault();
        const id = $('#closurePropertyId').val();
        const data = $(this).serialize();
        
        Swal.fire({
            title: 'Confirmar Encerramento?',
            text: "O imóvel será marcado como vendido/alugado e sairá do ar.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sim, encerrar!',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#4f46e5'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post(`<?= site_url('admin/properties') ?>/${id}/close`, data, function(res) {
                    if (res.success) {
                        bootstrap.Modal.getInstance(document.getElementById('closureModal')).hide();
                        Toast.fire({ icon: 'success', title: res.message });
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                });
            }
        });
    });

    function toggleDestaque(id) {
        $.post(`<?= site_url('admin/properties') ?>/${id}/toggle-destaque`, function(res) {
            if (res.success) {
                Toast.fire({ icon: 'success', title: res.message });
                setTimeout(() => location.reload(), 1000);
            } else {
                Swal.fire('Limite Atingido', res.message, 'warning');
            }
        }).fail(function() {
            Swal.fire('Erro', 'Falha ao processar solicitação.', 'error');
        });
    }
</script>
<?= $this->endSection() ?>


