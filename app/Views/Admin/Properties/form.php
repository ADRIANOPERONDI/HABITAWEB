<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?><?= isset($property) ? 'Editar' : 'Novo' ?> Imóvel<?= $this->endSection() ?>
<?= $this->section('page_title') ?><?= isset($property) ? 'Editar Imóvel' : 'Cadastrar Novo Imóvel' ?><?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .form-section-card { border: none; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 2rem; background: #fff; }
    .nav-tabs-premium { border-bottom: 2px solid #f0f2f5; gap: 1rem; }
    .nav-tabs-premium .nav-link { border: none; padding: 1rem 1.5rem; color: #67748e; font-weight: 500; border-radius: 10px; transition: all 0.3s; position: relative; }
    .nav-tabs-premium .nav-link.active { color: var(--bs-primary); background: rgba(var(--bs-primary-rgb), 0.05); }
    .nav-tabs-premium .nav-link.active::after { content: ''; position: absolute; bottom: 0; left: 0; width: 100%; height: 3px; background: var(--bs-primary); border-radius: 3px; }
    .form-label-premium { font-weight: 600; color: #344767; font-size: 0.85rem; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.5px; }
    .input-premium { border-radius: 10px; padding: 0.75rem 1rem; border: 1px solid #d2d6da; transition: all 0.2s; background-color: #ffffff; }
    .input-premium:focus { border-color: var(--bs-primary); box-shadow: 0 0 0 2px rgba(var(--bs-primary-rgb), 0.1); background-color: #fff; outline: none; }
    .dropzone-premium { border: 2px dashed #d2d6da; border-radius: 16px; padding: 3rem; background: #fafbfc; transition: all 0.3s; cursor: pointer; text-align: center; }
    .dropzone-premium:hover { border-color: var(--bs-primary); background: rgba(var(--bs-primary-rgb), 0.02); }
    .gallery-item-premium { border-radius: 12px; overflow: hidden; position: relative; box-shadow: 0 4px 8px rgba(0,0,0,0.1); transition: transform 0.3s; }
    .gallery-item-premium:hover { transform: scale(1.02); }
    .btn-save-fixed { position: fixed; bottom: 2rem; right: 2rem; z-index: 1000; box-shadow: 0 4px 15px rgba(0,0,0,0.2); padding: 0.8rem 2.5rem; border-radius: 50px; }
    
    /* Widget Score CSS */
    .circular-chart { display: block; margin: 0 auto; max-width: 80%; max-height: 250px; }
    .circle-bg { fill: none; stroke: #eee; stroke-width: 2.5; }
    .circle { fill: none; stroke-width: 2.5; stroke-linecap: round; transition: stroke-dasharray 1s ease-out; }
    .primary-chart .circle { stroke: var(--bs-primary); }

    /* Fix Select2 + Input Group Premium */
    .input-group > .select2-container--bootstrap-5 { flex: 1 1 auto; width: 1% !important; }
    .input-group > .select2-container--bootstrap-5 .select2-selection { 
        border-top-right-radius: 0 !important; 
        border-bottom-right-radius: 0 !important; 
        border-color: #d2d6da; 
        background-color: #ffffff; 
        height: 48px !important; 
        display: flex; 
        align-items: center; 
        box-shadow: none !important;
    }
    .input-group > .btn-quick-add { 
        border-top-left-radius: 0 !important; 
        border-bottom-left-radius: 0 !important; 
        border: 1px solid #d2d6da !important;
        border-left: none !important; 
        background-color: #f8f9fa; 
        color: var(--bs-primary); 
        width: 54px; 
        height: 48px;
        display: flex; 
        align-items: center; 
        justify-content: center; 
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 4;
    }
    .input-group > .btn-quick-add:hover { 
        background-color: var(--bs-primary); 
        color: white; 
        border-color: var(--bs-primary) !important; 
    }
    .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered { 
        padding-left: 1.2rem !important; 
        color: #344767; 
        font-weight: 500;
    }

    /* Modal Premium Adjustment */
    #quickClientModal .modal-content {
        border-radius: 20px;
        box-shadow: 0 20px 27px 0 rgba(0, 0, 0, 0.1);
        border: none;
    }
    #quickClientModal .modal-header {
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        border-bottom: none;
    }
    #quickClientModal .modal-dialog {
        max-width: 450px;
        margin: 1.75rem auto;
    }
    @media (max-height: 600px) {
        #quickClientModal .modal-dialog { margin: 0.5rem auto; }
        #quickClientModal .modal-body { padding-top: 0.5rem !important; padding-bottom: 0.5rem !important; }
    }
</style>
<!-- Leaflet CSS -->

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />

<!-- CKEditor 5 -->
<style>
    .ck-editor__editable { min-height: 250px; border-radius: 0 0 10px 10px !important; }
    #map { height: 350px; z-index: 10; border: 2px solid #f0f2f5; }
    
    /* Efeito de elevação no hover para cards interativos */
    .hover-lift {
        transition: all 0.3s ease;
    }
    .hover-lift:hover {
        transform: translateY(-4px);
        box-shadow: 0 0.5rem 1.5rem rgba(0,0,0,0.15) !important;
    }
    
    /* Corrige checkbox para não escapar do card */
    .form-check.bg-light .form-check-input {
        margin-left: 0 !important;
        margin-top: 0 !important;
    }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row justify-content-center pb-5">
    <!-- Main Form Column -->
    <div class="col-lg-8">
    
        <div class="d-flex align-items-center justify-content-between mb-4">
            <a href="<?= site_url('admin/properties') ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                <i class="fa-solid fa-chevron-left me-1"></i> Voltar
            </a>
            <?php if(isset($property) && (auth()->user()->inGroup('superadmin', 'admin'))): ?>
                <div class="badge bg-primary-soft text-primary p-2 px-3 rounded-pill border">
                    <i class="fa-solid fa-briefcase me-2"></i> 
                    Dono: <strong><?= esc($property->account_name ?? 'Conta Principal') ?></strong>
                </div>
            <?php endif; ?>
        </div>

        <?php $action = isset($property) ? site_url('admin/properties/' . $property->id) : site_url('admin/properties') ?>
        <form action="<?= $action ?>" method="post" id="propertyForm">
            <?= csrf_field() ?>
            <?php if(isset($property)): ?>
            <input type="hidden" name="_method" value="PUT">
            <?php endif; ?>

            <!-- Navigation Tabs -->
            <ul class="nav nav-tabs nav-tabs-premium mb-4 border-0" id="propertyTabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#basics" type="button">
                        <i class="fa-solid fa-info-circle me-2"></i> Informações Básicas
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#location" type="button">
                        <i class="fa-solid fa-location-dot me-2"></i> Localização
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#media" type="button">
                        <i class="fa-solid fa-images me-2"></i> Mídia & Fotos
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#details" type="button">
                        <i class="fa-solid fa-list-ul me-2"></i> Detalhes & SEO
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#settings" type="button">
                        <i class="fa-solid fa-gear me-2"></i> Status
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="propertyTabContent">
                
                <!-- Basics Tab -->
                <div class="tab-pane fade show active" id="basics">
                    <div class="card form-section-card">
                        <div class="card-body p-4">
                            <h5 class="fw-bold text-dark mb-4">Sobre o Imóvel</h5>
                            <div class="row g-4">
                                <div class="col-12">
                                    <label class="form-label-premium text-muted">Título do Anúncio</label>
                                    <input type="text" name="titulo" class="form-control input-premium form-control-lg" required value="<?= old('titulo', $property->titulo ?? '') ?>" placeholder="Ex: Apartamento de Luxo com 3 suítes no Jardins">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label-premium">Tipo de Negócio</label>
                                    <div class="d-flex gap-3 mt-1">
                                        <div class="form-check custom-option border p-3 rounded-3 flex-fill text-center <?= (old('tipo_negocio', $property->tipo_negocio ?? 'VENDA') == 'VENDA') ? 'bg-primary-soft border-primary' : '' ?>">
                                            <input class="form-check-input d-none" type="radio" name="tipo_negocio" id="negocio_venda" value="VENDA" <?= (old('tipo_negocio', $property->tipo_negocio ?? 'VENDA') == 'VENDA') ? 'checked' : '' ?>>
                                            <label class="form-check-label w-100 cursor-pointer" for="negocio_venda">
                                                <i class="fa-solid fa-money-bill-transfer fa-2x mb-2 d-block"></i>
                                                <span class="fw-bold">VENDA</span>
                                            </label>
                                        </div>
                                        <div class="form-check custom-option border p-3 rounded-3 flex-fill text-center <?= (old('tipo_negocio', $property->tipo_negocio ?? '') == 'ALUGUEL') ? 'bg-primary-soft border-primary' : '' ?>">
                                            <input class="form-check-input d-none" type="radio" name="tipo_negocio" id="negocio_aluguel" value="ALUGUEL" <?= (old('tipo_negocio', $property->tipo_negocio ?? '') == 'ALUGUEL') ? 'checked' : '' ?>>
                                            <label class="form-check-label w-100 cursor-pointer" for="negocio_aluguel">
                                                <i class="fa-solid fa-key fa-2x mb-2 d-block"></i>
                                                <span class="fw-bold">ALUGUEL</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label-premium">Preço Sugerido (R$)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0 rounded-start-pill ps-3">R$</span>
                                        <input type="text" name="preco" class="form-control input-premium border-start-0 ps-1 double3" required value="<?= number_format((float)old('preco', $property->preco ?? 0), 2, ',', '.') ?>" placeholder="0.000,00">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label-premium">Vincular Cliente (Proprietário)</label>
                                    <div class="input-group">
                                        <select name="client_id" class="form-select">
                                            <option value="">-- Selecione o proprietário --</option>
                                            <?php if(isset($property) && $property->client_id): ?>
                                                <option value="<?= $property->client_id ?>" selected><?= esc($property->client_name) ?></option>
                                            <?php endif; ?>
                                        </select>
                                        <button type="button" class="btn btn-quick-add" data-bs-toggle="modal" data-bs-target="#quickClientModal" title="Cadastro Rápido">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="small text-muted mt-1" style="font-size: 0.75rem;">
                                        Não encontrou o cliente? Clique no <strong>+</strong> para cadastro rápido.
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label-premium">Área Total (m²)</label>
                                    <input type="text" name="area_total" class="form-control input-premium double3" value="<?= number_format((float)old('area_total', $property->area_total ?? 0), 2, ',', '.') ?>" placeholder="0,00">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label-premium">Área Útil (m²)</label>
                                    <input type="text" name="area_construida" class="form-control input-premium double3" value="<?= number_format((float)old('area_construida', $property->area_construida ?? 0), 2, ',', '.') ?>" placeholder="0,00">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label-premium">Valor Condomínio (R$)</label>
                                    <input type="text" name="valor_condominio" class="form-control input-premium double3" value="<?= number_format((float)old('valor_condominio', $property->valor_condominio ?? 0), 2, ',', '.') ?>" placeholder="0,00">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label-premium">Nome do Condomínio</label>
                                    <input type="text" name="condominio" class="form-control input-premium" value="<?= old('condominio', $property->condominio ?? '') ?>" placeholder="Ex: Edifício Solar">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label-premium">IPTU Anual (R$)</label>
                                    <input type="text" name="iptu" class="form-control input-premium double3" value="<?= number_format((float)old('iptu', $property->iptu ?? 0), 2, ',', '.') ?>" placeholder="0,00">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label-premium">Corretor Responsável</label>
                                    <select name="user_id_responsavel" class="form-select input-premium" required>
                                        <option value="">-- Selecione o corretor --</option>
                                        <?php foreach($brokers as $broker): ?>
                                            <option value="<?= $broker->id ?>" <?= (old('user_id_responsavel', $property->user_id_responsavel ?? auth()->id()) == $broker->id) ? 'selected' : '' ?>>
                                                <?= esc($broker->getDisplayName()) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="small text-muted mt-1">
                                        Quem cuidará deste atendimento?
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label-premium">Tipo de Imóvel</label>
                                    <select name="tipo_imovel" class="form-select input-premium" required>
                                        <option value="APARTAMENTO" <?= (old('tipo_imovel', $property->tipo_imovel ?? '') == 'APARTAMENTO') ? 'selected' : '' ?>>Apartamento</option>
                                        <option value="CASA" <?= (old('tipo_imovel', $property->tipo_imovel ?? '') == 'CASA') ? 'selected' : '' ?>>Casa</option>
                                        <option value="TERRENO" <?= (old('tipo_imovel', $property->tipo_imovel ?? '') == 'TERRENO') ? 'selected' : '' ?>>Terreno</option>
                                        <option value="COMERCIAL" <?= (old('tipo_imovel', $property->tipo_imovel ?? '') == 'COMERCIAL') ? 'selected' : '' ?>>Comercial</option>
                                        <option value="COBERTURA" <?= (old('tipo_imovel', $property->tipo_imovel ?? '') == 'COBERTURA') ? 'selected' : '' ?>>Cobertura</option>
                                        <option value="SOBRADO" <?= (old('tipo_imovel', $property->tipo_imovel ?? '') == 'SOBRADO') ? 'selected' : '' ?>>Sobrado</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label-premium">Descrição do Anúncio</label>
                                    <textarea name="descricao" id="editor" class="form-control input-premium" rows="6" placeholder="Descreva os detalhes que encantam..."><?= old('descricao', $property->descricao ?? '') ?></textarea>
                                </div>

                                <!-- Características Técnicas (Card) -->
                                <div class="col-12 mt-4">
                                    <h6 class="fw-bold mb-3"><i class="fa-solid fa-house-crack text-primary me-2"></i> Composição do Imóvel</h6>
                                    <div class="row g-3">
                                        <div class="col-md-2">
                                            <label class="form-label-premium xsmall">Dormitórios</label>
                                            <input type="number" name="quartos" class="form-control input-premium" value="<?= old('quartos', $property->quartos ?? 0) ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label-premium xsmall">Suítes</label>
                                            <input type="number" name="suites" class="form-control input-premium" value="<?= old('suites', $property->suites ?? 0) ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label-premium xsmall">Banheiros</label>
                                            <input type="number" name="banheiros" class="form-control input-premium" value="<?= old('banheiros', $property->banheiros ?? 0) ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label-premium xsmall">Vagas</label>
                                            <input type="number" name="vagas" class="form-control input-premium" value="<?= old('vagas', $property->vagas ?? 0) ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label-premium xsmall">Renda Mensal Est. (Investidor)</label>
                                            <input type="text" name="renda_mensal_estimada" class="form-control input-premium double3" value="<?= number_format((float)old('renda_mensal_estimada', $property->renda_mensal_estimada ?? 0), 2, ',', '.') ?>" placeholder="0,00">
                                        </div>
                                    </div>
                                </div>

                                <!-- Características & Lazer -->
                                <div class="col-12 mt-4">
                                    <h6 class="fw-bold mb-3"><i class="fa-solid fa-list-check text-primary me-2"></i> Comodidades & Diferenciais</h6>
                                    <div class="row g-3">
                                        <?php 
                                            $possibleFeatures = [
                                                'piscina' => 'Piscina',
                                                'academia' => 'Academia',
                                                'churrasqueira' => 'Churrasqueira',
                                                'ar_condicionado' => 'Ar Condicionado',
                                                'elevador' => 'Elevador',
                                                'salao_festas' => 'Salão de Festas',
                                                'playground' => 'Playground',
                                                'varanda' => 'Varanda/Terraço',
                                                'portaria_24h' => 'Portaria 24h'
                                            ];
                                            
                                            $currentFeatures = [];
                                            // Carrega as características vinculadas
                                            $dbFeatures = $property->features ?? [];
                                            foreach ($dbFeatures as $f) {
                                                // Suporta tanto Objeto quanto Array
                                                $key = is_object($f) ? ($f->chave ?? '') : ($f['chave'] ?? '');
                                                if ($key) $currentFeatures[$key] = true;
                                            }
                                        ?>
                                        <?php foreach($possibleFeatures as $key => $label): ?>
                                            <div class="col-6 col-md-4 col-lg-3">
                                                <div class="form-check custom-checkbox-premium">
                                                    <input class="form-check-input" type="checkbox" name="features[]" value="<?= $key ?>" id="feat_<?= $key ?>" <?= isset($currentFeatures[$key]) ? 'checked' : '' ?>>
                                                    <label class="form-check-label small" for="feat_<?= $key ?>">
                                                        <?= $label ?>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Details & SEO Tab -->
                <div class="tab-pane fade" id="details">
                    <div class="card form-section-card">
                        <div class="card-body p-4">
                            <!-- Selos e Destaques -->
                            <div class="mb-5">
                                <div class="d-flex align-items-center mb-3">
                                    <i class="fa-solid fa-award text-warning fs-4 me-2"></i>
                                    <h5 class="fw-bold text-dark mb-0">Selos Promocionais (Gratuitos)</h5>
                                </div>
                                <p class="text-muted small mb-4">
                                    Marque características especiais sem custo extra.
                                    <span class="text-primary fw-bold ms-1"><i class="fa-solid fa-circle-info me-1"></i> Selos são visuais e não garantem prioridade na busca.</span>
                                </p>
                                
                                <div class="alert alert-light border shadow-sm d-flex align-items-center mb-4 rounded-4 p-3">
                                    <i class="fa-solid fa-rocket text-primary fs-2 me-3"></i>
                                    <div class="small">
                                        <strong class="text-dark">Quer aparecer no TOPO dos resultados?</strong>
                                        <div class="text-muted">Use o <a href="<?= site_url('admin/promotions') ?>" class="fw-bold text-primary text-decoration-none">Turbinamento Premium</a> para este imóvel receber 10x mais contatos.</div>
                                    </div>
                                </div>
                                
                                <div class="row g-3">
                                    <div class="col-lg-4">
                                        <div class="card border-0 shadow-sm h-100 hover-lift">
                                            <div class="card-body p-4">
                                                <div class="form-check form-switch d-flex align-items-center justify-content-between">
                                                    <div class="d-flex align-items-center">
                                                        <i class="fa-solid fa-star text-warning fs-3 me-3"></i>
                                                        <label class="form-check-label fw-semibold mb-0" for="is_destaque">
                                                            Selo Destaque
                                                            <small class="d-block text-muted fw-normal">Badge amarelo nos resultados</small>
                                                        </label>
                                                    </div>
                                                    <input class="form-check-input ms-3" type="checkbox" role="switch" name="is_destaque" id="is_destaque" value="1" style="width: 3rem; height: 1.5rem; cursor: pointer;" <?= old('is_destaque', $property->is_destaque ?? false) ? 'checked' : '' ?>>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-lg-4">
                                        <div class="card border-0 shadow-sm h-100 hover-lift">
                                            <div class="card-body p-4">
                                                <div class="form-check form-switch d-flex align-items-center justify-content-between">
                                                    <div class="d-flex align-items-center">
                                                        <i class="fa-solid fa-sparkles text-primary fs-3 me-3"></i>
                                                        <label class="form-check-label fw-semibold mb-0" for="is_novo">
                                                            Imóvel Novo
                                                            <small class="d-block text-muted fw-normal">Nunca habitado ou lançamento</small>
                                                        </label>
                                                    </div>
                                                    <input class="form-check-input ms-3" type="checkbox" role="switch" name="is_novo" id="is_novo" value="1" style="width: 3rem; height: 1.5rem; cursor: pointer;" <?= old('is_novo', $property->is_novo ?? false) ? 'checked' : '' ?>>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-lg-4">
                                        <div class="card border-0 shadow-sm h-100 hover-lift">
                                            <div class="card-body p-4">
                                                <div class="form-check form-switch d-flex align-items-center justify-content-between">
                                                    <div class="d-flex align-items-center">
                                                        <i class="fa-solid fa-shield-halved text-success fs-3 me-3"></i>
                                                        <label class="form-check-label fw-semibold mb-0" for="is_exclusivo">
                                                            Exclusividade
                                                            <small class="d-block text-muted fw-normal">Apenas nesta imobiliária</small>
                                                        </label>
                                                    </div>
                                                    <input class="form-check-input ms-3" type="checkbox" role="switch" name="is_exclusivo" id="is_exclusivo" value="1" style="width: 3rem; height: 1.5rem; cursor: pointer;" <?= old('is_exclusivo', $property->is_exclusivo ?? false) ? 'checked' : '' ?>>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Características Avançadas -->
                            <div class="mb-5">
                                <div class="d-flex align-items-center mb-3">
                                    <i class="fa-solid fa-sliders text-primary fs-4 me-2"></i>
                                    <h5 class="fw-bold text-dark mb-0">Atributos Adicionais</h5>
                                </div>
                                <p class="text-muted small mb-4">Marque as características que se aplicam ao imóvel</p>
                                
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body p-4">
                                        <div class="row g-3">
                                            <div class="col-md-6 col-lg-3">
                                                <div class="form-check bg-light p-3 rounded-3 h-100 d-flex align-items-center">
                                                    <input class="form-check-input me-2 flex-shrink-0" type="checkbox" name="aceita_pets" value="1" id="aceita_pets" style="width: 1.25rem; height: 1.25rem; cursor: pointer;" <?= old('aceita_pets', $property->aceita_pets ?? false) ? 'checked' : '' ?>>
                                                    <label class="form-check-label fw-medium mb-0" for="aceita_pets" style="cursor: pointer;">
                                                        <i class="fa-solid fa-paw text-primary me-1"></i> Aceita Pets
                                                    </label>
                                                </div>
                                            </div>

                                            <div class="col-md-6 col-lg-3">
                                                <div class="form-check bg-light p-3 rounded-3 h-100 d-flex align-items-center">
                                                    <input class="form-check-input me-2 flex-shrink-0" type="checkbox" name="mobiliado" value="1" id="mobiliado" style="width: 1.25rem; height: 1.25rem; cursor: pointer;" <?= old('mobiliado', $property->mobiliado ?? false) ? 'checked' : '' ?>>
                                                    <label class="form-check-label fw-medium mb-0" for="mobiliado" style="cursor: pointer;">
                                                        <i class="fa-solid fa-couch text-success me-1"></i> Mobiliado
                                                    </label>
                                                </div>
                                            </div>

                                            <div class="col-md-6 col-lg-3">
                                                <div class="form-check bg-light p-3 rounded-3 h-100 d-flex align-items-center">
                                                    <input class="form-check-input me-2 flex-shrink-0" type="checkbox" name="semimobiliado" value="1" id="semimobiliado" style="width: 1.25rem; height: 1.25rem; cursor: pointer;" <?= old('semimobiliado', $property->semimobiliado ?? false) ? 'checked' : '' ?>>
                                                    <label class="form-check-label fw-medium mb-0" for="semimobiliado" style="cursor: pointer;">
                                                        <i class="fa-solid fa-box-open text-info me-1"></i> Semimobiliado
                                                    </label>
                                                </div>
                                            </div>

                                            <div class="col-md-6 col-lg-3">
                                                <div class="form-check bg-light p-3 rounded-3 h-100 d-flex align-items-center">
                                                    <input class="form-check-input me-2 flex-shrink-0" type="checkbox" name="is_desocupado" value="1" id="is_desocupado" style="width: 1.25rem; height: 1.25rem; cursor: pointer;" <?= old('is_desocupado', $property->is_desocupado ?? false) ? 'checked' : '' ?>>
                                                    <label class="form-check-label fw-medium mb-0" for="is_desocupado" style="cursor: pointer;">
                                                        <i class="fa-solid fa-key text-warning me-1"></i> Desocupado
                                                    </label>
                                                </div>
                                            </div>

                                            <div class="col-md-6 col-lg-3">
                                                <div class="form-check bg-light p-3 rounded-3 h-100 d-flex align-items-center">
                                                    <input class="form-check-input me-2 flex-shrink-0" type="checkbox" name="is_locado" value="1" id="is_locado" style="width: 1.25rem; height: 1.25rem; cursor: pointer;" <?= old('is_locado', $property->is_locado ?? false) ? 'checked' : '' ?>>
                                                    <label class="form-check-label fw-medium mb-0" for="is_locado" style="cursor: pointer;">
                                                        <i class="fa-solid fa-home text-secondary me-1"></i> Está Locado
                                                    </label>
                                                </div>
                                            </div>

                                            <div class="col-md-6 col-lg-3">
                                                <div class="form-check bg-light p-3 rounded-3 h-100 d-flex align-items-center">
                                                    <input class="form-check-input me-2 flex-shrink-0" type="checkbox" name="indicado_investidor" value="1" id="indicado_investidor" style="width: 1.25rem; height: 1.25rem; cursor: pointer;" <?= old('indicado_investidor', $property->indicado_investidor ?? false) ? 'checked' : '' ?>>
                                                    <label class="form-check-label fw-medium mb-0" for="indicado_investidor" style="cursor: pointer;">
                                                        <i class="fa-solid fa-chart-line text-primary me-1"></i> Ideal p/ Investidor
                                                    </label>
                                                </div>
                                            </div>

                                            <div class="col-md-6 col-lg-3">
                                                <div class="form-check bg-light p-3 rounded-3 h-100 d-flex align-items-center">
                                                    <input class="form-check-input me-2 flex-shrink-0" type="checkbox" name="indicado_primeira_moradia" value="1" id="indicado_primeira_moradia" style="width: 1.25rem; height: 1.25rem; cursor: pointer;" <?= old('indicado_primeira_moradia', $property->indicado_primeira_moradia ?? false) ? 'checked' : '' ?>>
                                                    <label class="form-check-label fw-medium mb-0" for="indicado_primeira_moradia" style="cursor: pointer;">
                                                        <i class="fa-solid fa-heart text-danger me-1"></i> Primeira Moradia
                                                    </label>
                                                </div>
                                            </div>

                                            <div class="col-md-6 col-lg-3">
                                                <div class="form-check bg-light p-3 rounded-3 h-100 d-flex align-items-center">
                                                    <input class="form-check-input me-2 flex-shrink-0" type="checkbox" name="indicado_temporada" value="1" id="indicado_temporada" style="width: 1.25rem; height: 1.25rem; cursor: pointer;" <?= old('indicado_temporada', $property->indicado_temporada ?? false) ? 'checked' : '' ?>>
                                                    <label class="form-check-label fw-medium mb-0" for="indicado_temporada" style="cursor: pointer;">
                                                        <i class="fa-solid fa-umbrella-beach text-info me-1"></i> Aluguel Temporada
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- SEO -->
                            <div>
                                <div class="d-flex align-items-center mb-3">
                                    <i class="fa-solid fa-magnifying-glass-chart text-success fs-4 me-2"></i>
                                    <h5 class="fw-bold text-dark mb-0">Otimização para Buscas (SEO)</h5>
                                </div>
                                <p class="text-muted small mb-4">Melhore a posição deste imóvel nos resultados de busca do Google</p>
                                
                                <div class="card border-0 shadow-sm">
                                    <div class="card-body p-4">
                                        <div class="row g-4">
                                            <div class="col-12">
                                                <label class="form-label fw-semibold">
                                                    <i class="fa-solid fa-heading text-primary me-1"></i>
                                                    META TÍTULO (SLUG PERSONALIZADO)
                                                </label>
                                                <input type="text" name="meta_title" class="form-control form-control-lg" value="<?= old('meta_title', $property->meta_title ?? '') ?>" placeholder="Apartamento à venda em São Sebastião, São Miguel do Oeste">
                                                <small class="text-muted">
                                                    <i class="fa-solid fa-circle-info me-1"></i>
                                                    Se deixar vazio, o sistema gerará automaticamente.
                                                </small>
                                            </div>
                                            
                                            <div class="col-12">
                                                <label class="form-label fw-semibold">
                                                    <i class="fa-solid fa-align-left text-success me-1"></i>
                                                    META DESCRIÇÃO
                                                </label>
                                                <textarea name="meta_description" class="form-control" rows="4" placeholder="Apartamento à venda no Bairro América – Joinville/SC. Imóvel com 3 dormitórios e 86m² de área total. Entre em contato para mais informações."><?= old('meta_description', $property->meta_description ?? '') ?></textarea>
                                                <small class="text-muted">
                                                    <i class="fa-solid fa-lightbulb me-1"></i>
                                                    Descreva brevemente o imóvel em 150-160 caracteres. Isso aparece no Google.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Location Tab -->
                <div class="tab-pane fade" id="location">
                    <div class="card form-section-card">
                        <div class="card-body p-4">
                            <h5 class="fw-bold text-dark mb-4">Onde está localizado?</h5>
                            <div class="row g-4">
                                <div class="col-md-4">
                                    <label class="form-label-premium">CEP</label>
                                    <input type="text" name="cep" class="form-control input-premium" value="<?= old('cep', $property->cep ?? '') ?>" placeholder="00000-000">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label-premium">Rua / Logradouro</label>
                                    <input type="text" name="rua" class="form-control input-premium" value="<?= old('rua', $property->rua ?? '') ?>" placeholder="Av. Paulista...">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label-premium">Cidade</label>
                                    <input type="text" name="cidade" class="form-control input-premium" value="<?= old('cidade', $property->cidade ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label-premium">Bairro</label>
                                    <input type="text" name="bairro" class="form-control input-premium" value="<?= old('bairro', $property->bairro ?? '') ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label-premium">Número</label>
                                    <input type="text" name="numero" class="form-control input-premium" value="<?= old('numero', $property->numero ?? '') ?>">
                                </div>
                                <div class="col-md-9">
                                    <label class="form-label-premium">Complemento</label>
                                    <input type="text" name="complemento" class="form-control input-premium" value="<?= old('complemento', $property->complemento ?? '') ?>" placeholder="Apto 12, Bloco B...">
                                </div>
                                <div class="col-12">
                                    <label class="form-label-premium">Localização Exata</label>
                                    <p class="text-muted small mb-2"><i class="fa-solid fa-circle-info me-1"></i> Arraste o marcador para ajustar a posição precisa no mapa.</p>
                                    <div id="map" class="rounded-4 mb-3"></div>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label-premium small">Latitude</label>
                                            <input type="text" name="latitude" id="lat" class="form-control input-premium" value="<?= old('latitude', $property->latitude ?? '') ?>" placeholder="Ex: -23.5505">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label-premium small">Longitude</label>
                                            <input type="text" name="longitude" id="lng" class="form-control input-premium" value="<?= old('longitude', $property->longitude ?? '') ?>" placeholder="Ex: -46.6333">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Media Tab -->
                <div class="tab-pane fade" id="media">
                    <div class="card form-section-card">
                        <div class="card-body p-4 text-center">
                            <div id="mediaTabPlaceholder" class="<?= isset($property) ? 'd-none' : '' ?>">
                                <div class="py-5">
                                    <i class="fa-solid fa-lock fa-4x text-muted mb-4"></i>
                                    <h4 class="fw-bold text-muted">Aguardando rascunho</h4>
                                    <p class="text-muted">Primeiro salve as informações básicas para poder enviar as fotos.</p>
                                    <button type="button" class="btn btn-primary px-5 rounded-pill mt-3 btn-ajax-save" data-status="DRAFT">Salvar Rascunho e Liberar Fotos</button>
                                </div>
                            </div>
                            
                            <div id="mediaTabContent" class="<?= !isset($property) ? 'd-none' : '' ?>">
                                <h5 class="fw-bold text-dark mb-4">Galeria de Fotos</h5>
                                <div class="dropzone-premium mb-4" id="dropZone">
                                    <i class="fa-solid fa-cloud-arrow-up fa-3x text-primary mb-3"></i>
                                    <h6 class="fw-bold">Arraste suas fotos aqui</h6>
                                    <p class="text-muted small">Ou clique para selecionar arquivos do seu computador</p>
                                    <input type="file" id="fileInput" class="d-none" multiple accept="image/*">
                                    <button type="button" class="btn btn-primary rounded-pill px-4 mt-2" onclick="document.getElementById('fileInput').click()">
                                        Procurar Fotos
                                    </button>
                                    
                                    <div class="progress mt-4 d-none" id="uploadProgress" style="height: 10px; border-radius: 5px;">
                                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
                                    </div>
                                </div>

                                <div class="row g-3" id="galleryContainer">
                                    <!-- AJAX loaded pics -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Settings Tab -->
                <div class="tab-pane fade" id="settings">
                    <div class="card form-section-card border-primary border">
                        <div class="card-body p-4">
                            <h5 class="fw-bold text-primary mb-4">Configurações de Exposição</h5>
                            <div class="row g-4 align-items-center">
                                <div class="col-md-8">
                                    <h6 class="fw-bold mb-1">Status do Anúncio</h6>
                                    <p class="text-muted small mb-0">Ativo para aparecer no portal ou Rascunho para continuar editando depois.</p>
                                </div>
                                <div class="col-md-4">
                                    <select name="status" class="form-select input-premium">
                                        <option value="ACTIVE" <?= (old('status', $property->status ?? '') == 'ACTIVE') ? 'selected' : '' ?>>Ativo (Público)</option>
                                        <option value="DRAFT" <?= (old('status', $property->status ?? '') == 'DRAFT' || !isset($property)) ? 'selected' : '' ?>>Rascunho (Privado)</option>
                                        <option value="PAUSED" <?= (old('status', $property->status ?? '') == 'PAUSED') ? 'selected' : '' ?>>Pausado</option>
                                        <option value="SOLD" <?= (old('status', $property->status ?? '') == 'SOLD') ? 'selected' : '' ?>>Vendido / Finalizado</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Floating Save Button -->
            <div class="btn-save-fixed d-flex gap-2">
                <button type="button" class="btn btn-outline-primary rounded-pill px-4 btn-ajax-save shadow-sm" data-status="DRAFT" id="btnDraftSave">
                    <i class="fa-solid fa-file-pen me-2"></i> <?= isset($property) ? 'Atualizar Rascunho' : 'Salvar Rascunho' ?>
                </button>
                <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-lg" id="btnMainSave">
                    <i class="fa-solid fa-save me-2"></i> Finalizar & Publicar
                </button>
            </div>

        </form>
    </div>
    
    <!-- Sidebar Score (Sticky) -->
    <div class="col-lg-4 d-none d-lg-block">
        <div class="sticky-top" style="top: 100px; z-index: 999;">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden" id="scoreWidget">
                <div class="card-header bg-white border-bottom p-4">
                    <h5 class="fw-bold mb-1"><i class="fa-solid fa-chart-line text-primary me-2"></i> Qualidade do Anúncio</h5>
                    <small class="text-muted">Anúncios completos vendem 3x mais rápido.</small>
                </div>
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <div class="position-relative d-inline-block">
                            <svg viewBox="0 0 36 36" class="circular-chart primary-chart" style="width: 120px; height: 120px;">
                                <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                <path class="circle" id="scoreCircle" stroke-dasharray="0, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            </svg>
                            <div class="position-absolute top-50 start-50 translate-middle">
                                <h2 class="fw-bold mb-0" id="scoreValue">0</h2>
                                <small class="text-muted fw-bold">PONTOS</small>
                            </div>
                        </div>
                    </div>

                    <div id="scoreFeedback">
                        <div class="d-flex align-items-center mb-2 text-success">
                            <i class="fa-solid fa-check-circle me-2"></i> <span>Começando...</span>
                        </div>
                    </div>

                    <hr class="my-3">
                    
                    <h6 class="fw-bold text-muted text-uppercase xsmall mb-3">Sugestões para melhorar:</h6>
                    <ul class="list-unstyled small text-muted" id="scoreSuggestions">
                        <li><i class="fa-regular fa-circle me-2"></i> Preencha o título</li>
                        <li><i class="fa-regular fa-circle me-2"></i> Descreva o imóvel</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Client Modal -->
<div class="modal fade" id="quickClientModal" tabindex="-1" aria-labelledby="quickClientModalLabel" aria-hidden="true" data-bs-backdrop="false" style="z-index: 1060; background-color: rgba(0,0,0,0.1);">
    <div class="modal-dialog modal-dialog-scrollable" style="margin-top: 50px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 1.5rem;">
            <div class="modal-header border-bottom-0 pb-0">
                <div class="p-3 w-100 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold mb-0" id="quickClientModalLabel">Novo Proprietário</h5>
                        <p class="text-muted small mb-0">Cadastro simples.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <form id="quickClientForm">
                <div class="modal-body px-4 py-3">
                    <div class="mb-3">
                        <label class="form-label-premium mb-2">Nome do Cliente</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0 rounded-start-pill border-secondary border-opacity-25"><i class="fa-solid fa-user text-primary"></i></span>
                            <input type="text" name="nome" class="form-control input-premium border-start-0 border-secondary border-opacity-25" required placeholder="Ex: João da Silva" autofocus>
                        </div>
                    </div>
                    <?php if(auth()->user()->inGroup('superadmin', 'admin')): ?>
                        <div class="mb-2">
                            <label class="form-label-premium mb-2">Conta / Imobiliária</label>
                            <select name="account_id" class="form-select input-premium border-secondary border-opacity-25">
                                <?php foreach($accounts ?? [] as $acc): ?>
                                    <option value="<?= $acc->id ?>" <?= ($acc->id == auth()->user()->account_id) ? 'selected' : '' ?>><?= esc($acc->nome) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-0 px-4 pb-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold text-muted" data-bs-dismiss="modal">Fechar</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" id="btnSaveQuickClient">
                        <span class="spinner-border spinner-border-sm d-none me-2"></span> Salvar Cliente
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>



<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>
<script src="https://cdn.ckeditor.com/ckeditor5/40.0.0/classic/ckeditor.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
$(document).ready(function() {
    // --- AJAX Save Draft Flow ---
    $('.btn-ajax-save').on('click', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const forceStatus = $btn.data('status');
        const $form = $('#propertyForm');
        
        // Sincroniza CKEditor se existir
        if (window.editorInstance) {
            $form.find('textarea[name="descricao"]').val(window.editorInstance.getData());
        }

        let formData = new FormData($form[0]);
        if (forceStatus) {
            formData.set('status', forceStatus);
        }

        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span> Salvando...');

        $.ajax({
            url: $form.attr('action'),
            type: 'POST', // CI4 resource uses POST with _method=PUT for updates
            data: formData,
            processData: false,
            contentType: false,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            success: function(res) {
                if (res.success) {
                    Toast.fire({ icon: 'success', title: res.message });
                    
                    if (res.id) {
                        // Novo imóvel criado
                        propertyId = res.id;
                        $form.attr('action', '<?= site_url("admin/properties") ?>/' + res.id);
                        if ($form.find('input[name="_method"]').length === 0) {
                            $form.prepend('<input type="hidden" name="_method" value="PUT">');
                        }
                        uploadUrl = '<?= site_url("admin/properties") ?>/' + res.id + '/media';
                        
                        // Atualiza UI
                        $('#mediaTabPlaceholder').addClass('d-none');
                        $('#mediaTabContent').removeClass('d-none');
                        $('#btnDraftSave').html('<i class="fa-solid fa-file-pen me-2"></i> Atualizar Rascunho');
                        
                        // Opcional: Ir para aba de mídia
                        const mediaTab = new bootstrap.Tab(document.querySelector('button[data-bs-target="#media"]'));
                        mediaTab.show();
                    }
                    
                    if (res.redirect) {
                        setTimeout(() => window.location.href = res.redirect, 1000);
                    }
                } else {
                    let errorMsg = res.message || 'Falha ao salvar';
                    if (res.errors) {
                        errorMsg = Object.values(res.errors).join('<br>');
                    }
                    Swal.fire('Atenção', errorMsg, 'warning');
                }
            },
            error: function() {
                Swal.fire('Erro', 'Erro de conexão com o servidor.', 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).html(forceStatus === 'DRAFT' ? '<i class="fa-solid fa-file-pen me-2"></i> ' + (propertyId ? 'Atualizar Rascunho' : 'Salvar Rascunho') : '<i class="fa-solid fa-save me-2"></i> Finalizar & Publicar');
            }
        });
    });

    // Select2 Integration for Client Search
    $('select[name="client_id"]').select2({
        theme: 'bootstrap-5',
        width: '100%',
        language: 'pt-BR',
        placeholder: 'Busque pelo nome ou CPF/CNPJ...',
        allowClear: true,
        minimumInputLength: 2,
        ajax: {
            url: '<?= site_url("admin/clients/search") ?>',
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    term: params.term // search term
                };
            },
            processResults: function (data) {
                return {
                    results: data.results
                };
            },
            cache: true
        }
    });

    // Quick Client Registration Logic
    $('#quickClientModal').on('shown.bs.modal', function () {
        $('input[name="nome"]', this).focus();
    });

    $('#quickClientForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $('#btnSaveQuickClient');
        const $spinner = $btn.find('.spinner-border');
        
        let formData = {};
        $(this).serializeArray().forEach(item => {
            formData[item.name] = item.value;
        });

        $btn.prop('disabled', true);
        $spinner.removeClass('d-none');

        $.ajax({
            url: '<?= site_url("admin/clients/quick") ?>',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(formData),
            headers: { 
                'X-Requested-With': 'XMLHttpRequest',
                '<?= csrf_header() ?>': '<?= csrf_hash() ?>'
            },
            success: function(res) {
                if(res.success) {
                    // Create option and select it
                    const newOption = new Option(res.client.nome, res.client.id, true, true);
                    $('select[name="client_id"]').append(newOption).trigger('change');
                    
                    $('#quickClientModal').modal('hide');
                    $('#quickClientForm')[0].reset();
                    
                    Toast.fire({ icon: 'success', title: res.message });
                } else {
                    Swal.fire('Erro!', res.message || 'Falha ao cadastrar cliente', 'error');
                }
            },
            error: function() {
                Swal.fire('Erro!', 'Ocorreu um erro na comunicação com o servidor.', 'error');
            },
            complete: function() {
                $btn.prop('disabled', false);
                $spinner.addClass('d-none');
            }
        });
    });
    // Custom Radio Styling Logic
    $('.custom-option').click(function() {
        $(this).find('input[type="radio"]').prop('checked', true);
        $('.custom-option').removeClass('bg-primary-soft border-primary');
        $(this).addClass('bg-primary-soft border-primary');
    });


    // --- Media Upload Logic (Drag & Drop) ---
    let propertyId = '<?= $property->id ?? "" ?>';
    let uploadUrl = propertyId ? '<?= site_url("admin/properties") ?>/' + propertyId + '/media' : '';
        
        // Initial Gallery Load (Simulated)
        // Note: In a real app, you'd fetch existing media here. 

        $('#fileInput').on('change', function() {
            handleFiles(this.files);
        });

        const dropZone = document.getElementById('dropZone');
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eName => {
            dropZone.addEventListener(eName, e => { e.preventDefault(); e.stopPropagation(); }, false);
        });

        ['dragenter', 'dragover'].forEach(eName => {
            dropZone.addEventListener(eName, () => $(dropZone).addClass('border-primary bg-primary-soft'), false);
        });

        ['dragleave', 'drop'].forEach(eName => {
            dropZone.addEventListener(eName, () => $(dropZone).removeClass('border-primary bg-primary-soft'), false);
        });

        // Initial Gallery Load
        <?php if(isset($property) && isset($property->images)): ?>
            <?php foreach($property->images as $img): ?>
                addPic('<?= base_url($img->url) ?>', <?= $img->id ?>, <?= json_encode((bool)$img->principal) ?>);
            <?php endforeach; ?>
        <?php endif; ?>

        dropZone.addEventListener('drop', e => handleFiles(e.dataTransfer.files), false);

        function handleFiles(files) {
            if(files.length === 0) return;
            
            $('#uploadProgress').removeClass('d-none');
            let total = files.length;
            let done = 0;
            
            Array.from(files).forEach(file => {
                let fd = new FormData();
                fd.append('file', file);
                fd.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');
                
                $.ajax({
                    url: uploadUrl,
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(res) {
                        if(res.success) {
                            addPic(res.url, res.id, res.is_main);
                        } else {
                            Swal.fire('Erro!', res.error || 'Falha no upload', 'error');
                        }
                    },
                    complete: function() {
                        done++;
                        $('.progress-bar').css('width', (done/total*100) + '%');
                        if(done === total) {
                            setTimeout(() => {
                                $('#uploadProgress').addClass('d-none');
                                $('.progress-bar').css('width', '0%');
                                Toast.fire({ icon: 'success', title: 'Upload concluído!' });
                            }, 800);
                        }
                    }
                });
            });
        }

        function addPic(url, id, isMain) {
            // Converte explicitamente para boolean para evitar strings "false" serem truthy
            isMain = !!isMain;
            let activeClass = isMain ? 'border-primary border-3 shadow-lg' : '';
            let btnClass = isMain ? 'btn-warning text-white' : 'btn-outline-secondary';
            let badge = isMain ? '<span class="position-absolute top-0 start-0 badge bg-warning m-2 shadow-sm"><i class="fa-solid fa-crown me-1"></i>CAPA</span>' : '';

            let html = `
                <div class="col-6 col-md-4 col-lg-3 mb-3 media-item-wrapper" id="media-${id}">
                    <div class="gallery-item-premium position-relative rounded overflow-hidden ${activeClass}" style="height: 220px; border: 2px solid ${isMain ? '#0d6efd' : '#dee2e6'};">
                        <img src="${url}" class="w-100 h-100 object-fit-cover" alt="Imagem do imóvel">
                        ${badge}
                        <div class="position-absolute bottom-0 end-0 p-2 d-flex gap-2">
                             <button type="button" onclick="setMain(${id})" class="btn btn-sm rounded shadow-sm ${btnClass}" title="Definir como Capa">
                                <i class="fa-solid fa-star"></i>
                             </button>
                             <button type="button" onclick="delPic(${id})" class="btn btn-danger btn-sm rounded shadow-sm" title="Excluir">
                                <i class="fa-solid fa-trash"></i>
                             </button>
                        </div>
                    </div>
                </div>
            `;
            if (isMain) {
                $('#galleryContainer').prepend(html);
            } else {
                $('#galleryContainer').append(html);
            }
        }

    window.setMain = function(id) {
        $.ajax({
            url: '<?= site_url("admin/media") ?>/' + id + '/main',
            type: 'POST',
            data: { '<?= csrf_token() ?>': '<?= csrf_hash() ?>' },
            success: function(res) {
                if(res.success) {
                    Toast.fire({ icon: 'success', title: 'Capa atualizada!' });
                    
                    // Reset visuals
                    $('.media-item-wrapper .gallery-item-premium').removeClass('border-primary border-3 shadow-lg');
                    $('.media-item-wrapper .badge').remove();
                    $('.media-item-wrapper .btn-warning').removeClass('btn-warning text-white').addClass('btn-light text-muted');
                    
                    // Set new active
                    let $container = $(`#media-${id} .gallery-item-premium`);
                    $container.addClass('border-primary border-3 shadow-lg');
                    $container.append('<span class="position-absolute top-0 start-0 badge bg-warning m-2 shadow-sm">CAPA</span>');
                    $container.find('button[onclick^="setMain"]').removeClass('btn-light text-muted').addClass('btn-warning text-white');
                }
            }
        });
    }

    window.delPic = function(id) {
        Swal.fire({
            title: 'Tem certeza?',
            text: "Esta foto será excluída permanentemente!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6e7d88',
            confirmButtonText: 'Sim, excluir!',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '<?= site_url("admin/media") ?>/' + id,
                    type: 'DELETE',
                    data: { '<?= csrf_token() ?>': '<?= csrf_hash() ?>' },
                    success: function(res) {
                        if(res.success) {
                            $(`#media-${id}`).fadeOut(function() { $(this).remove(); });
                            Toast.fire({ icon: 'success', title: 'Foto removida.' });
                        }
                    }
                });
            }
        });
    }

    // --- Real-time Score Calculation ---
    // Debounce function to limit API calls
    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    const calculateScore = debounce(function() {
        // Gather form data
        let formData = {};
        $('#propertyForm').serializeArray().forEach(item => {
            formData[item.name] = item.value;
        });
        
        // Add Media Count (simulated from DOM if present)
        let mediaCount = $('#galleryContainer').children().length;
        formData['media_count'] = mediaCount;
        formData['id'] = '<?= $property->id ?? '' ?>'; // pass ID if exists

        $.ajax({
            url: '<?= site_url("admin/properties/calculate-score") ?>',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(formData),
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': '<?= csrf_hash() ?>'
            },
            success: function(res) {
                if(res.status && res.data) {
                    updateScoreWidget(res.data);
                }
            },
            error: function(xhr) {
                // Determine if 401/403
                console.log("Score update failed", xhr);
            }
        });
    }, 1000); // 1 sec delay

    function updateScoreWidget(data) {
        const score = data.score;
        const suggestions = data.suggestions;
        const breakdown = data.breakdown;

        // Update Circle
        $('#scoreCircle').attr('stroke-dasharray', `${score}, 100`);
        
        // Update Number with animation
        $({ Counter: $('#scoreValue').text() }).animate({ Counter: score }, {
            duration: 1000,
            easing: 'swing',
            step: function() {
                $('#scoreValue').text(Math.ceil(this.Counter));
            }
        });

        // Color coding
        let color = '#dc3545'; // red
        if(score >= 50) color = '#ffb400'; // orange
        if(score >= 80) color = '#198754'; // green
        $('.primary-chart .circle').css('stroke', color);

        // Update Breakdown list
        let breakdownHtml = '';
        for (const [key, val] of Object.entries(breakdown)) {
            breakdownHtml += `<div class="d-flex justify-content-between small mb-1"><span>${key}</span><span class="fw-bold text-success">${val}</span></div>`;
        }
        $('#scoreFeedback').html(breakdownHtml || '<small class="text-muted">Preencha os campos...</small>');

        // Update Suggestions
        let suggHtml = '';
        if(suggestions.length === 0 && score === 100) {
            suggHtml = '<li class="text-success fw-bold"><i class="fa-solid fa-trophy me-2"></i> Anúncio Perfeito!</li>';
        } else {
            suggestions.forEach(s => {
                suggHtml += `<li><i class="fa-regular fa-circle me-2 text-warning"></i> ${s}</li>`;
            });
        }
        $('#scoreSuggestions').html(suggHtml);
    }

    // --- CKEditor 5 Initialization ---
    ClassicEditor
        .create(document.querySelector('#editor'), {
            toolbar: ['heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'numberedList', 'blockQuote', 'undo', 'redo']
        })
        .then(editor => {
            window.editorInstance = editor; // Export instance
            editor.model.document.on('change:data', () => {
                calculateScore();
            });
        })
        .catch(error => console.error(error));

    // --- Masks & ViaCEP ---
    $.getScript('https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js', function() {
        $('input[name="cep"]').mask('00000-000');
    });

    $('input[name="cep"]').on('blur', function() {
        var cep = $(this).val().replace(/\D/g, '');
        if (cep != "") {
            var validacep = /^[0-9]{8}$/;
            if(validacep.test(cep)) {
                // Feedback visual de carregamento
                const fields = ['rua', 'bairro', 'cidade'];
                fields.forEach(f => $(`input[name="${f}"]`).val('...'));

                $.getJSON("https://viacep.com.br/ws/"+ cep +"/json/?callback=?", function(dados) {
                    if (!("erro" in dados)) {
                        $('input[name="rua"]').val(dados.logradouro);
                        $('input[name="bairro"]').val(dados.bairro);
                        $('input[name="cidade"]').val(dados.localidade);
                        
                        // Cidades com CEP único não retornam rua/bairro.
                        // Nesses casos, focamos na rua para o usuário completar.
                        if (!dados.logradouro) {
                            $('input[name="rua"]').focus();
                        } else {
                            $('input[name="numero"]').focus();
                        }
                        
                        // Atualiza o mapa se tivermos rua/cidade
                        geocodeAddress();
                    } else {
                        fields.forEach(f => $(`input[name="${f}"]`).val(''));
                        Toast.fire({ icon: 'error', title: 'CEP não encontrado.' });
                    }
                });
            }
        }
    });

    // --- Leaflet Map Logic ---
    var defaultLat = <?= $property->latitude ?? -23.55052 ?>;
    var defaultLng = <?= $property->longitude ?? -46.633308 ?>;
    
    var map = L.map('map').setView([defaultLat, defaultLng], 15);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var marker = L.marker([defaultLat, defaultLng], {
        draggable: true
    }).addTo(markerGroup = L.layerGroup().addTo(map));

    marker.on('dragend', function(event) {
        var pos = marker.getLatLng();
        $('#lat').val(pos.lat);
        $('#lng').val(pos.lng);
        calculateScore();
    });

    // Fix map render inside tab
    $('button[data-bs-target="#location"]').on('shown.bs.tab', function() {
        map.invalidateSize();
    });

    function updateMap(lat, lng, updateInputs = true) {
        if (!lat || !lng) return;
        var newPos = new L.LatLng(lat, lng);
        marker.setLatLng(newPos);
        map.panTo(newPos);
        if (updateInputs) {
            $('#lat').val(lat);
            $('#lng').val(lng);
        }
        calculateScore();
    }

    // Nominatim Geocoding with Fallbacks
    function geocodeAddress() {
        var rua = $('input[name="rua"]').val();
        var num = $('input[name="numero"]').val();
        var bairro = $('input[name="bairro"]').val();
        var cidade = $('input[name="cidade"]').val();
        
        if (!cidade) return;

        // Estratégia de busca em cascata (Fallbacks)
        const queries = [
            `${rua} ${num}, ${bairro}, ${cidade}, Brazil`, // Busca exata
            `${rua}, ${bairro}, ${cidade}, Brazil`,       // Rua e Bairro
            `${bairro}, ${cidade}, Brazil`,              // Apenas Bairro
            `${cidade}, Brazil`                          // Apenas Cidade
        ];

        function tryGeocode(index) {
            if (index >= queries.length) return;
            
            $.get(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(queries[index])}&limit=1`, function(data) {
                if (data.length > 0) {
                    updateMap(data[0].lat, data[0].lon);
                } else {
                    tryGeocode(index + 1);
                }
            });
        }

        tryGeocode(0);
    }

    // Sincronização manual (Inputs -> Mapa)
    $('#lat, #lng').on('change blur', function() {
        var lat = $('#lat').val().replace(',', '.');
        var lng = $('#lng').val().replace(',', '.');
        if (lat && lng) {
            updateMap(lat, lng, false);
        }
    });

    $('input[name="rua"], input[name="numero"], input[name="bairro"], input[name="cidade"]').on('blur', geocodeAddress);

    // --- Controle de Limite de Selos de Destaque ---
    function checkDestaqueLimit() {
        const propertyId = "<?= $property->id ?? '' ?>";
        const url = `<?= site_url('admin/properties/check-destaque-limit') ?>${propertyId ? '?id='+propertyId : ''}`;

        fetch(url)
            .then(r => r.json())
            .then(data => {
                const switchInput = $('#is_destaque');
                const container = switchInput.closest('.card');
                
                // Remove alertas anteriores
                container.find('.alert-limit-destaque').remove();
                
                if (!data.allowed && !switchInput.is(':checked')) {
                    // Se não permitido e NÃO está marcado, desabilita e avisa
                    switchInput.prop('disabled', true);
                    container.find('.card-body').append(`
                        <div class="alert alert-warning x-small mb-0 mt-3 p-2 rounded-3 alert-limit-destaque">
                            <i class="fa-solid fa-lock me-1"></i> ${data.message}
                            <a href="<?= site_url('admin/subscription') ?>" class="alert-link ms-1">Fazer Upgrade</a>
                        </div>
                    `);
                } else {
                    // Se permitido ou já está marcado (permite desmarcar), mostra info
                    switchInput.prop('disabled', false);
                    if (data.allowed) {
                        container.find('.card-body').append(`
                            <div class="text-muted x-small mt-3 alert-limit-destaque">
                                <i class="fa-solid fa-circle-check text-success me-1"></i> 
                                Você possui <strong>${data.remaining}</strong> selos disponíveis.
                            </div>
                        `);
                    }
                }
            })
            .catch(e => console.error('Erro ao verificar limite de destaque:', e));
    }

    // Chama ao carregar
    checkDestaqueLimit();

    // Attach listeners
    $('#propertyForm input, #propertyForm textarea, #propertyForm select').on('change input blur', calculateScore);
    
    // Initial call
    calculateScore();
});
</script>
<?= $this->endSection() ?>
