<?= $this->extend('Layouts/public') ?>

<?= $this->section('title') ?>Meus Favoritos<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container py-5">
    <h1 class="fw-bold mb-4"><i class="fa-solid fa-heart text-danger"></i> Meus Favoritos</h1>

    <?php if (empty($properties)): ?>
        <div class="alert alert-light text-center py-5">
            <h4>Você ainda não tem imóveis favoritos.</h4>
            <a href="<?= site_url('imoveis') ?>" class="btn btn-primary mt-3">Buscar Imóveis</a>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($properties as $property): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 border-0 shadow-sm rounded-4 overflow-hidden card-hover">
                        <div class="position-relative">
                            <span class="badge bg-primary position-absolute top-0 start-0 m-3"><?= esc($property->tipo_negocio) ?></span>
                            <!-- Cover Image -->
                            <?php 
                                $cover = $property->cover_image ? base_url($property->cover_image) : 'https://placehold.co/600x400?text=Sem+Foto';
                            ?>
                            <img src="<?= $cover ?>" class="card-img-top object-fit-cover" height="250" alt="<?= esc($property->titulo) ?>">
                            
                            <!-- Favorite Button (Always Active in this list) -->
                            <button class="btn btn-light rounded-circle position-absolute top-0 end-0 m-3 btn-favorite shadow-sm" data-id="<?= $property->id ?>">
                                <i class="fa-solid fa-heart text-danger"></i>
                            </button>
                        </div>
                        <div class="card-body">
                            <h5 class="card-title fw-bold text-truncate"><?= esc($property->titulo) ?></h5>
                            <p class="text-muted small mb-2">
                                <i class="fa-solid fa-location-dot"></i> <?= esc($property->bairro) ?>, <?= esc($property->cidade) ?>
                            </p>
                            <div class="d-flex justify-content-between align-items-center mt-3">
                                <div>
                                    <span class="d-block small text-muted">A partir de</span>
                                    <span class="h5 fw-bold text-primary mb-0">R$ <?= number_format($property->preco, 2, ',', '.') ?></span>
                                </div>
                                <a href="<?= site_url('imovel/' . $property->id) ?>" class="btn btn-outline-primary rounded-pill btn-sm">Detalhes</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
