<?= $this->extend('Layouts/public') ?>

<?= $this->section('content') ?>

<!-- Partner Hero/Header -->
<section class="bg-white border-bottom">
    <div class="container py-5">
        <div class="row align-items-center">
            <div class="col-md-3 text-center mb-4 mb-md-0">
                <?php if(!empty($partner->logo)): ?>
                    <img src="<?= base_url('uploads/logos/'.$partner->logo) ?>" 
                         alt="<?= esc($partner->nome) ?>" 
                         class="rounded-circle shadow-lg object-fit-cover border border-4 border-white"
                         style="width: 180px; height: 180px;">
                <?php else: ?>
                    <div class="rounded-circle bg-primary-soft d-flex align-items-center justify-content-center mx-auto shadow-lg border border-4 border-white" style="width: 180px; height: 180px;">
                        <span class="display-3 text-primary fw-bold mb-0"><?= substr($partner->nome, 0, 1) ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-9">
                <div class="d-flex align-items-center gap-3 mb-2">
                    <h1 class="fw-bold text-dark mb-0"><?= esc($partner->nome) ?></h1>
                    <?php if($partner->tipo_conta == 'IMOBILIARIA'): ?>
                        <span class="badge bg-primary rounded-pill px-3">Imobiliária</span>
                    <?php elseif($partner->tipo_conta == 'CORRETOR'): ?>
                        <span class="badge bg-secondary rounded-pill px-3">Corretor</span>
                    <?php else: ?>
                        <span class="badge bg-info rounded-pill px-3">Anunciante</span>
                    <?php endif; ?>
                </div>

                <?php if(!empty($partner->creci)): ?>
                    <p class="text-muted h5 mb-4">CRECI: <?= esc($partner->creci) ?></p>
                <?php endif; ?>

                <div class="d-flex flex-wrap gap-3">
                    <?php if(!empty($partner->whatsapp)): ?>
                        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $partner->whatsapp) ?>" target="_blank" class="btn btn-success rounded-pill px-4">
                            <i class="fab fa-whatsapp me-2"></i> WhatsApp
                        </a>
                    <?php endif; ?>
                    
                    <?php if(!empty($partner->telefone)): ?>
                        <a href="tel:<?= $partner->telefone ?>" class="btn btn-outline-dark rounded-pill px-4">
                            <i class="fas fa-phone me-2"></i> <?= esc($partner->telefone) ?>
                        </a>
                    <?php endif; ?>

                    <?php if(!empty($partner->email)): ?>
                        <a href="mailto:<?= $partner->email ?>" class="btn btn-outline-dark rounded-pill px-4">
                            <i class="fas fa-envelope me-2"></i> Email
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Partner Properties -->
<section class="py-5 bg-light">
    <div class="container">
        <h2 class="h4 fw-bold mb-4">Imóveis de <?= esc($partner->nome) ?></h2>

        <?php if(empty($properties)): ?>
            <div class="text-center py-5">
                <i class="fas fa-home fa-3x text-muted mb-3 opacity-25"></i>
                <h3 class="h5 text-muted">Nenhum imóvel disponível no momento.</h3>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach($properties as $property): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 border-0 shadow-premium hover-lift overflow-hidden">
                            <div class="position-relative" style="height: 220px;">
                                <a href="<?= base_url('imovel/'.$property->id) ?>" class="text-decoration-none">
                                    <!-- Use first image or placeholder -->
                                    <img src="https://placehold.co/600x400/f1f5f9/94a3b8?text=Sem+Foto" class="w-100 h-100 object-fit-cover" alt="Imóvel">
                                    
                                    <span class="position-absolute top-0 end-0 m-3 badge bg-white text-dark shadow-sm">
                                        <?= $property->tipo_negocio == 'venda' ? 'Venda' : 'Aluguel' ?>
                                    </span>
                                </a>
                            </div>
                            <div class="card-body p-4 d-flex flex-column">
                                <h5 class="card-title fw-bold text-dark mb-1 h6">
                                    <a href="<?= base_url('imovel/'.$property->id) ?>" class="text-decoration-none text-dark">
                                        <?= esc($property->titulo) ?>
                                    </a>
                                </h5>
                                <p class="small text-muted mb-3">
                                    <i class="fas fa-map-marker-alt me-1"></i> 
                                    <?= esc($property->bairro) ?>, <?= esc($property->cidade) ?>
                                </p>
                                
                                <div class="mt-auto">
                                    <h4 class="fw-bold text-primary mb-0">
                                        R$ <?= number_format($property->preco, 2, ',', '.') ?>
                                    </h4>
                                    
                                    <div class="d-flex justify-content-between mt-3 pt-3 border-top small text-muted">
                                        <span><i class="fas fa-vector-square me-1"></i> <?= $property->area_total ?> m²</span>
                                        <span><i class="fas fa-bed me-1"></i> <?= $property->quartos ?></span>
                                        <span><i class="fas fa-bath me-1"></i> <?= $property->banheiros ?></span>
                                        <span><i class="fas fa-car me-1"></i> <?= $property->vagas ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-5 d-flex justify-content-center">
                <?= $pager->links('default', 'premium') ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?= $this->endSection() ?>
