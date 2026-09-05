<?= $this->extend('Layouts/public') ?>
<?= $this->section('title') ?><?= esc($partner->nome ?? 'Parceiro') ?><?= $this->endSection() ?>

<?php if (!empty($partner->latitude) && !empty($partner->longitude)): ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<?= $this->endSection() ?>
<?php endif; ?>

<?= $this->section('content') ?>

<!-- Capa -->
<?php if (!empty($partner->capa)): ?>
    <div class="w-100" style="height: 280px; overflow: hidden; background: #0b1220;">
        <img src="<?= media_url($partner->capa) ?>" alt="Capa de <?= esc($partner->nome) ?>" class="w-100 h-100 object-fit-cover" loading="lazy" decoding="async">
    </div>
<?php endif; ?>

<!-- Partner Hero/Header -->
<section class="bg-white border-bottom">
    <div class="container py-5">
        <div class="row align-items-center">
            <div class="col-md-3 text-center mb-4 mb-md-0">
                <?php if(!empty($partner->logo)): ?>
                    <img src="<?= media_url($partner->logo) ?>"
                         alt="<?= esc($partner->nome) ?>"
                         class="rounded-circle shadow-lg object-fit-cover border border-4 border-white"
                         style="width: 180px; height: 180px; <?= !empty($partner->capa) ? 'margin-top: -90px;' : '' ?>">
                <?php else: ?>
                    <div class="rounded-circle bg-primary-soft d-flex align-items-center justify-content-center mx-auto shadow-lg border border-4 border-white" style="width: 180px; height: 180px; <?= !empty($partner->capa) ? 'margin-top: -90px;' : '' ?>">
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
                    <?php if($partner->is_verified): ?>
                        <span class="text-primary" title="Parceiro Verificado"><i class="fa-solid fa-circle-check"></i></span>
                    <?php endif; ?>
                </div>

                <?php if(!empty($partner->creci)): ?>
                    <p class="text-muted h5 mb-3">CRECI: <?= esc($partner->creci) ?></p>
                <?php endif; ?>

                <?php if(!empty($partner->descricao)): ?>
                    <p class="text-muted mb-3"><?= nl2br(esc($partner->descricao)) ?></p>
                <?php endif; ?>

                <?php if(!empty($partner->cidade)): ?>
                    <p class="text-muted small mb-3">
                        <i class="fas fa-map-marker-alt me-1"></i>
                        <?= esc(trim(implode(', ', array_filter([$partner->rua && $partner->numero ? $partner->rua . ', ' . $partner->numero : $partner->rua, $partner->bairro, $partner->cidade . ($partner->estado ? '/' . $partner->estado : '')])))) ?>
                    </p>
                <?php endif; ?>

                <?php if(!empty($partner->horario_atendimento)): ?>
                    <p class="text-muted small mb-3"><i class="fas fa-clock me-1"></i> <?= esc($partner->horario_atendimento) ?></p>
                <?php endif; ?>

                <div class="d-flex flex-wrap gap-3 mb-3">
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

                    <?php if(!empty($partner->site)): ?>
                        <a href="<?= esc($partner->site, 'attr') ?>" target="_blank" rel="noopener" class="btn btn-outline-dark rounded-pill px-4">
                            <i class="fas fa-globe me-2"></i> Site
                        </a>
                    <?php endif; ?>
                </div>

                <?php
                    $redes = [
                        'instagram' => 'fa-brands fa-instagram',
                        'facebook'  => 'fa-brands fa-facebook',
                        'linkedin'  => 'fa-brands fa-linkedin',
                        'youtube'   => 'fa-brands fa-youtube',
                        'tiktok'    => 'fa-brands fa-tiktok',
                    ];
                    $redesPreenchidas = array_filter($redes, static fn ($rede) => !empty($partner->$rede), ARRAY_FILTER_USE_KEY);
                ?>
                <?php if ($redesPreenchidas): ?>
                    <div class="d-flex gap-3">
                        <?php foreach ($redesPreenchidas as $rede => $icon): ?>
                            <a href="<?= esc($partner->$rede, 'attr') ?>" target="_blank" rel="noopener" class="text-muted fs-5" title="<?= esc(ucfirst($rede)) ?>">
                                <i class="<?= $icon ?>"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php if (!empty($partner->latitude) && !empty($partner->longitude)): ?>
<section class="border-bottom">
    <div id="partnerMap" style="height: 280px;"></div>
</section>
<?php endif; ?>

<!-- Equipe -->
<?php if (!empty($team)): ?>
<section class="py-5 bg-white border-bottom">
    <div class="container">
        <h2 class="h4 fw-bold mb-4">Nossa Equipe</h2>
        <div class="row g-4">
            <?php foreach ($team as $member): ?>
                <div class="col-6 col-md-3 col-lg-2 text-center">
                    <?php if (!empty($member->foto)): ?>
                        <img src="<?= media_url($member->foto) ?>" alt="<?= esc($member->nome ?? $member->username) ?>" class="rounded-circle object-fit-cover mb-2" style="width: 90px; height: 90px;">
                    <?php else: ?>
                        <div class="rounded-circle bg-primary-soft d-flex align-items-center justify-content-center mx-auto mb-2" style="width: 90px; height: 90px;">
                            <span class="fs-3 text-primary fw-bold"><?= substr($member->nome ?? $member->username, 0, 1) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="fw-bold small text-dark"><?= esc($member->nome ?? $member->username) ?></div>
                    <?php if (!empty($member->cargo)): ?>
                        <div class="text-muted extra-small"><?= esc($member->cargo) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($member->creci)): ?>
                        <div class="text-muted extra-small">CRECI <?= esc($member->creci) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Partner Properties -->
<section class="py-5 bg-light">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <h2 class="h4 fw-bold mb-0">Imóveis de <?= esc($partner->nome) ?></h2>

            <ul class="nav nav-pills">
                <li class="nav-item">
                    <a class="nav-link <?= $aba === 'todos' ? 'active' : '' ?>" href="?aba=todos">Todos</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $aba === 'lancamentos' ? 'active' : '' ?>" href="?aba=lancamentos">Lançamentos</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $aba === 'destaques' ? 'active' : '' ?>" href="?aba=destaques">Destaques</a>
                </li>
            </ul>
        </div>

        <?php if(empty($properties)): ?>
            <div class="text-center py-5">
                <i class="fas fa-home fa-3x text-muted mb-3 opacity-25"></i>
                <h3 class="h5 text-muted">Nenhum imóvel <?= $aba === 'lancamentos' ? 'lançado' : ($aba === 'destaques' ? 'em destaque' : 'disponível') ?> no momento.</h3>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach($properties as $property): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card h-100 border-0 shadow-premium hover-lift overflow-hidden">
                            <div class="position-relative" style="height: 220px;">
                                <a href="<?= base_url('imovel/'.$property->id) ?>" class="text-decoration-none">
                                    <?php if($property->cover_image): ?>
                                        <img src="<?= media_variant_url($property->cover_image, 'card') ?>" class="w-100 h-100 object-fit-cover" alt="<?= esc($property->titulo) ?>" loading="lazy" decoding="async" onerror="this.src='<?= base_url('assets/img/placeholder-house.png') ?>'">
                                    <?php else: ?>
                                        <img src="<?= base_url('assets/img/placeholder-house.png') ?>" class="w-100 h-100 object-fit-cover" alt="Sem Foto" loading="lazy" decoding="async">
                                    <?php endif; ?>

                                    <span class="position-absolute top-0 end-0 m-3 badge bg-white text-dark shadow-sm">
                                        <?= $property->tipo_negocio === 'VENDA' ? 'Venda' : 'Aluguel' ?>
                                    </span>
                                    <?php if (!empty($property->is_novo)): ?>
                                        <span class="position-absolute top-0 start-0 m-3 badge bg-success shadow-sm">Novo</span>
                                    <?php endif; ?>
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

<?php if (!empty($partner->latitude) && !empty($partner->longitude)): ?>
<?= $this->section('scripts') ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const map = L.map('partnerMap').setView([<?= (float) $partner->latitude ?>, <?= (float) $partner->longitude ?>], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    L.marker([<?= (float) $partner->latitude ?>, <?= (float) $partner->longitude ?>]).addTo(map);
});
</script>
<?= $this->endSection() ?>
<?php endif; ?>
