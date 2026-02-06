<?= $this->extend('Layouts/public') ?>

<?= $this->section('content') ?>

<!-- Hero Section -->
<section class="py-5 bg-primary-soft position-relative overflow-hidden">
    <div class="container position-relative z-1">
        <div class="row justify-content-center text-center">
            <div class="col-lg-8">
                <span class="badge bg-white text-primary fw-bold shadow-sm mb-3 px-3 py-2 rounded-pill">
                    <i class="fas fa-handshake me-2"></i> Parceiros & Imobiliárias
                </span>
                <h1 class="display-4 fw-bold mb-3 text-dark">Encontre Imobiliárias e <span class="text-primary-gradient">Corretores</span></h1>
                <p class="lead text-muted mb-0">
                    Conheça nossos parceiros credenciados e encontre o profissional ideal para realizar seu sonho.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- Partners Grid -->
<section class="py-5 bg-white">
    <div class="container">
        <?php if(empty($partners)): ?>
            <div class="text-center py-5">
                <img src="<?= base_url('assets/images/empty.svg') ?>" alt="Nenhum parceiro" style="max-height: 200px; opacity: 0.5;" class="mb-4">
                <h3 class="h5 text-muted">Nenhum parceiro encontrado no momento.</h3>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach($partners as $partner): ?>
                <div class="col-md-6 col-lg-3">
                    <a href="<?= base_url('parceiro/'.$partner->id) ?>" class="text-decoration-none">
                        <div class="card h-100 border-0 shadow-premium hover-lift transition-all">
                            <div class="card-body text-center p-4 d-flex flex-column">
                                <div class="mb-4 mx-auto position-relative">
                                    <?php if(!empty($partner->logo)): ?>
                                        <img src="<?= base_url('uploads/logos/'.$partner->logo) ?>" 
                                             alt="<?= esc($partner->nome) ?>" 
                                             class="rounded-circle shadow-sm object-fit-cover"
                                             style="width: 100px; height: 100px;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-primary-soft d-flex align-items-center justify-content-center mx-auto" style="width: 100px; height: 100px;">
                                            <span class="h2 text-primary fw-bold mb-0"><?= substr($partner->nome, 0, 1) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if($partner->tipo_conta == 'IMOBILIARIA'): ?>
                                        <span class="position-absolute bottom-0 end-0 badge bg-primary rounded-pill border border-2 border-white" title="Imobiliária">
                                            <i class="fas fa-building"></i>
                                        </span>
                                    <?php elseif($partner->tipo_conta == 'CORRETOR'): ?>
                                        <span class="position-absolute bottom-0 end-0 badge bg-secondary rounded-pill border border-2 border-white" title="Corretor">
                                            <i class="fas fa-user-tie"></i>
                                        </span>
                                    <?php else: ?>
                                        <span class="position-absolute bottom-0 end-0 badge bg-info rounded-pill border border-2 border-white" title="Anunciante">
                                            <i class="fas fa-user"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <h5 class="card-title fw-bold text-dark mb-1"><?= esc($partner->nome) ?></h5>
                                
                                <?php if(!empty($partner->creci)): ?>
                                    <p class="small text-muted mb-3">CRECI: <?= esc($partner->creci) ?></p>
                                <?php endif; ?>

                                <div class="mt-auto pt-3 border-top">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted">
                                            <i class="fas fa-home me-1 text-primary"></i>
                                            <strong><?= $partner->total_properties ?? 0 ?></strong> Imóveis
                                        </small>
                                        <span class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                            Ver Perfil
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <div class="mt-5 d-flex justify-content-center">
                <?= $pager->links('default', 'premium') ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?= $this->endSection() ?>
