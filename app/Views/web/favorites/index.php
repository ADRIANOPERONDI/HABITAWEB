<?= $this->extend('Layouts/public') ?>

<?= $this->section('title') ?>Meus Favoritos<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="container py-5">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="fw-bold">
            <i class="fas fa-heart text-danger me-2"></i> Meus Favoritos
        </h1>
        <a href="<?= site_url('imoveis') ?>" class="btn btn-outline-primary rounded-pill">
            <i class="fas fa-search me-2"></i> Buscar mais imóveis
        </a>
    </div>

    <?php if (empty($properties)): ?>
        <div class="text-center py-5">
            <div class="mb-4">
                <i class="far fa-heart fa-5x text-muted opacity-25"></i>
            </div>
            <h3 class="text-muted fw-bold">Nenhum favorito ainda</h3>
            <p class="text-muted mb-4">Salve os imóveis que você mais gostou para ver depois.</p>
            <a href="<?= site_url('imoveis') ?>" class="btn btn-primary px-4 py-2 rounded-pill shadow-sm">
                Explorar Imóveis
            </a>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($properties as $property): ?>
                <div class="col-md-6 col-lg-4" id="fav-card-<?= $property->id ?>">
                    <div class="card h-100 border-0 shadow-sm hover-shadow transition-all">
                        <div class="position-relative">
                            <img src="<?= $property->imagem_destaque ?? 'https://placehold.co/600x400?text=Sem+Foto' ?>" 
                                 class="card-img-top" 
                                 alt="<?= esc($property->titulo) ?>"
                                 style="height: 200px; object-fit: cover;">
                            
                            <button class="btn btn-light rounded-circle position-absolute top-0 end-0 m-3 shadow-sm btn-favorite p-2"
                                    onclick="toggleFavorite(<?= $property->id ?>, true)"
                                    title="Remover dos favoritos">
                                <i class="fas fa-heart text-danger"></i>
                            </button>
                            
                            <span class="badge bg-dark position-absolute top-0 start-0 m-3">
                                <?= $property->tipo_negocio == 'venda' ? 'Venda' : 'Aluguel' ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <h5 class="card-title fw-bold text-truncate"><?= esc($property->titulo) ?></h5>
                            <p class="card-text text-muted small mb-2">
                                <i class="fas fa-map-marker-alt me-1"></i> 
                                <?= esc($property->bairro) ?>, <?= esc($property->cidade) ?>
                            </p>
                            
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="fw-bold text-primary fs-5">
                                    R$ <?= number_format($property->valor, 2, ',', '.') ?>
                                </span>
                            </div>
                            
                            <div class="row g-0 border-top pt-3 text-center small text-muted">
                                <div class="col">
                                    <i class="fas fa-bed mb-1 d-block"></i> <?= $property->quartos ?>
                                </div>
                                <div class="col">
                                    <i class="fas fa-bath mb-1 d-block"></i> <?= $property->banheiros ?>
                                </div>
                                <div class="col">
                                    <i class="fas fa-car mb-1 d-block"></i> <?= $property->vagas ?>
                                </div>
                                <div class="col">
                                    <i class="fas fa-ruler-combined mb-1 d-block"></i> <?= $property->area_util ?>m²
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-white border-0 py-3">
                            <a href="<?= site_url("imoveis/detalhes/{$property->slug}") ?>" class="btn btn-outline-primary w-100 rounded-pill">
                                Ver Detalhes
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleFavorite(propertyId, removeOnSuccess = false) {
    if (!confirm('Remover este imóvel dos favoritos?')) return;

    fetch(`<?= site_url('favoritos/toggle/') ?>${propertyId}`, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            '<?= csrf_token() ?>': '<?= csrf_hash() ?>'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Se estou na tela de favoritos, removo o card
            if (removeOnSuccess) {
                const card = document.getElementById(`fav-card-${propertyId}`);
                if (card) {
                    card.style.transition = 'opacity 0.5s ease';
                    card.style.opacity = '0';
                    setTimeout(() => card.remove(), 500);
                    
                    // Se não sobrar nenhum, recarregar para mostrar empty state
                    const remaining = document.querySelectorAll('[id^=fav-card-]').length - 1;
                    if (remaining <= 0) {
                        setTimeout(() => location.reload(), 600);
                    }
                }
            } else {
                alert(data.message);
            }
        } else {
            alert(data.error || 'Erro ao processar solicitação.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Erro de conexão.');
    });
}
</script>
<?= $this->endSection() ?>
