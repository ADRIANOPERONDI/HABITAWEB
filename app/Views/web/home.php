<?= $this->extend('Layouts/public') ?>

<?= $this->section('title') ?>Encontre seu lugar ideal<?= $this->endSection() ?>

<?= $this->section('content') ?>

<!-- Hero Section Airbnb-style -->
<section class="hero-section-clean" style="background-image: url('https://images.unsplash.com/photo-1512917774080-9991f1c4c750?auto=format&fit=crop&w=1920&q=80');">
    <div class="hero-text-content px-3">
        <h1 class="display-3 fw-bold text-white mb-2 animate-fade-in">Onde você quer morar?</h1>
        <p class="fs-4 text-white opacity-75 animate-fade-in" style="animation-delay: 0.1s;">Descubra os melhores imóveis da sua região com quem entende do assunto.</p>
    </div>
</section>

<!-- Floating Search Bar -->
<div class="container animate-fade-in" style="animation-delay: 0.2s;">
    
    <form action="<?= site_url('imoveis') ?>" method="GET" class="search-container-floating">
        
        <div class="search-item">
            <label>QUERO</label>
            <select name="tipo_negocio" class="select2-public">
                <option value="VENDA" selected>Comprar</option>
            <option value="ALUGUEL">Alugar</option>
            </select>
        </div>

        <div class="search-item flex-large">
            <label>CIDADE</label>
            <select name="cidade" class="select2-public">
                <option value="">Selecione</option>
                <?php foreach($cidades as $c): ?>
                    <option value="<?= esc($c->cidade) ?>"><?= esc($c->cidade) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="search-item">
            <label>CATEGORIA</label>
            <select name="tipo_imovel" class="select2-public">
                <option value="">Selecione</option>
                <?php foreach($tipos as $t): ?>
                    <option value="<?= esc($t->tipo_imovel) ?>"><?= esc(ucfirst(strtolower($t->tipo_imovel))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="search-item flex-large">
            <label>BAIRRO</label>
            <select name="bairro" class="select2-public">
                <option value="">Selecione</option>
                <?php foreach($bairros as $b): ?>
                    <option value="<?= esc($b->bairro) ?>"><?= esc($b->bairro) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="px-3">
            <button type="submit" class="btn-search-round">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
        </div>
    </form>
</div>

<?php if(!empty($sponsoredProperties)): ?>
<!-- Sponsored Properties (Unificados) -->
<section class="py-5 mt-4" style="background-color: #fdf8f0; border-top: 1px solid #ffeeba; border-bottom: 1px solid #ffeeba;">
    <div class="container pb-2">
        <div class="d-flex align-items-center mb-4 px-2">
            <div class="bg-warning rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                <i class="fa-solid fa-crown text-white fs-5"></i>
            </div>
            <div>
                <h2 class="section-title mb-0">Imóveis Patrocinados</h2>
                <p class="section-subtitle mb-0">Destaques exclusivos da nossa rede</p>
            </div>
        </div>
        
        <div class="row g-4 p-2">
            <?php foreach($sponsoredProperties as $property): ?>
            <div class="col-md-6 col-lg-3">
                <div class="card property-card h-100 animate-fade-in shadow-sm border-0 d-flex flex-column" style="border: 1px solid #ffeeba !important;">
                    <a href="<?= site_url('imovel/' . $property->id) ?>" class="text-decoration-none h-100 d-flex flex-column">
                        <div class="card-img-top-wrapper position-relative" style="height: 200px; overflow: hidden;">
                             <div class="position-absolute top-0 start-0 m-2 z-3 d-flex flex-column gap-2">
                                <span class="badge bg-warning text-dark shadow-sm rounded-pill px-3 py-2 fw-bold">
                                    <i class="fa-solid fa-certificate me-1"></i> Patrocinado
                                </span>
                             </div>
                            
                            <?php if($property->cover_image): ?>
                                <img src="<?= base_url($property->cover_image) ?>" class="card-img-top w-100 h-100" style="object-fit: cover;" alt="<?= esc($property->titulo) ?>">
                            <?php else: ?>
                                <img src="<?= base_url('assets/img/placeholder-house.png') ?>" class="card-img-top w-100 h-100" style="object-fit: cover;" alt="Sem Foto">
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-3 d-flex flex-column flex-grow-1 bg-white">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <h6 class="fw-bold mb-0 text-dark text-truncate w-100"><?= esc($property->bairro) ?>, <?= esc($property->cidade) ?></h6>
                            </div>
                            <p class="text-muted x-small mb-2 text-truncate"><?= esc($property->titulo) ?></p>

                            <div class="property-specs d-flex gap-2 mb-2 text-muted x-small border-bottom pb-2">
                                <?php if($property->area_total): ?>
                                    <span><i class="fa-solid fa-maximize"></i> <?= (int)$property->area_total ?>m²</span>
                                <?php endif; ?>
                                <?php if($property->quartos): ?>
                                    <span><i class="fa-solid fa-bed"></i> <?= $property->quartos ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mt-auto fw-bold text-primary">R$ <?= number_format($property->preco, 2, ',', '.') ?></div>
                        </div>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>



<!-- Featured Properties Section -->
<section class="py-5 bg-white">
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-end mb-4 px-2">
            <div>
                <h2 class="section-title">Destaques Recomendados</h2>
                <p class="section-subtitle mb-0">Imóveis verificados com alta qualidade visual.</p>
            </div>
            <a href="<?= site_url('imoveis') ?>" class="btn btn-link text-dark fw-bold text-decoration-none">
                Ver todos <i class="fa-solid fa-chevron-right ms-1"></i>
            </a>
        </div>
        
        <div class="row g-4 p-2">
            <?php if(empty($featuredProperties)): ?>
                <div class="col-12 text-center py-5">
                    <h4>Novos imóveis em breve.</h4>
                </div>
            <?php else: ?>
                <?php foreach($featuredProperties as $property): ?>
                <div class="col-md-6 col-lg-3 mb-4">
                    <div class="card property-card h-100 animate-fade-in shadow-sm border-0 d-flex flex-column">
                        <a href="<?= site_url('imovel/' . $property->id) ?>" class="text-decoration-none h-100 d-flex flex-column">
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
                                    
                                    <?php if($property->cover_image): ?>
                                        <img src="<?= base_url($property->cover_image) ?>" class="card-img-top w-100 h-100" style="object-fit: cover;" alt="<?= esc($property->titulo) ?>">
                                    <?php else: ?>
                                        <img src="<?= base_url('assets/img/placeholder-house.png') ?>" class="card-img-top w-100 h-100" style="object-fit: cover;" alt="Sem Foto">
                                    <?php endif; ?>
                                </div>
                                <div class="card-body p-3 d-flex flex-column flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <h6 class="fw-bold mb-0 text-dark text-truncate w-75"><?= esc($property->bairro) ?>, <?= esc($property->cidade) ?></h6>
                                        <?php if(isset($property->highlight_level) && $property->highlight_level > 0): ?>
                                            <small class="badge bg-secondary text-white"><i class="fa-solid fa-arrow-up me-1"></i> Top</small>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-muted x-small mb-2 text-truncate"><?= esc($property->titulo) ?></p>

                                    <div class="property-specs d-flex gap-2 mb-2 text-muted x-small border-bottom pb-2">
                                        <?php if($property->area_total): ?>
                                            <span title="Área Total"><i class="fa-solid fa-maximize"></i> <?= (int)$property->area_total ?>m²</span>
                                        <?php endif; ?>
                                        <?php if($property->quartos): ?>
                                            <span title="Quartos"><i class="fa-solid fa-bed"></i> <?= $property->quartos ?></span>
                                        <?php endif; ?>
                                        <?php if($property->banheiros): ?>
                                            <span title="Banheiros"><i class="fa-solid fa-bath"></i> <?= $property->banheiros ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mt-auto d-flex align-items-center justify-content-between">
                                        <div class="fw-bold text-dark">R$ <?= number_format($property->preco, 2, ',', '.') ?></div>
                                        <?php if($property->tipo_negocio === 'ALUGUEL'): ?>
                                            <small class="text-muted x-small">Mês</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Partners / Agencies Section -->
<section class="py-5 border-top bg-light">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="section-title">Nossos Parceiros</h2>
            <p class="section-subtitle">As melhores imobiliárias e corretores estão aqui</p>
        </div>
        
        <div class="row g-4 justify-content-center">
            <?php if(empty($partners)): ?>
                <div class="col-12 text-center opacity-50">
                    <p>Conheça nossos parceiros em breve.</p>
                </div>
            <?php else: ?>
                <?php foreach($partners as $partner): ?>
                <div class="col-6 col-md-3 col-lg-2">
                    <a href="<?= site_url('anunciante/' . $partner->id) ?>" class="text-decoration-none">
                        <div class="partner-card animate-fade-in bg-white shadow-sm h-100 p-3 rounded-4">
                            <img src="<?= base_url($partner->logo) ?>" alt="<?= esc($partner->nome) ?>" class="partner-logo mb-2">
                            <div class="small fw-bold text-dark text-truncate d-block"><?= esc($partner->nome) ?></div>
                            <span class="xsmall text-muted text-uppercase" style="font-size: 10px;"><?= esc($partner->tipo_conta) ?></span>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Call to Action Section -->
<section class="py-5">
    <div class="container">
        <div class="rounded-5 overflow-hidden position-relative p-5 text-white" style="background: linear-gradient(rgba(0,0,0,0.4), rgba(0,0,0,0.4)), url('https://images.unsplash.com/photo-1560518883-ce09059eeffa?auto=format&fit=crop&w=1200&q=80'); background-size: cover; background-position: center;">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h2 class="display-5 fw-bold mb-4">Anuncie seu imóvel para milhares de pessoas</h2>
                    <p class="fs-5 mb-5 opacity-75">Seja você um proprietário, corretor ou imobiliária, o nosso portal é o lugar certo para fechar negócio.</p>
                    <a href="<?= site_url('register') ?>" class="btn btn-light btn-lg rounded-pill px-5 py-3 fw-bold text-primary">Começar agora</a>
                </div>
            </div>
        </div>
    </div>
</section>

<?= $this->endSection() ?>
