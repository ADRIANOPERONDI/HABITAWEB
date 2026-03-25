<?= $this->extend('Layouts/public') ?>

<?= $this->section('title') ?>Sobre Nós<?= $this->endSection() ?>

<?= $this->section('meta_description') ?>Conheça a história, missão, visão e valores do <?= esc(app_setting('seo.title', 'Portal Imobiliário')) ?>.<?= $this->endSection() ?>

<?= $this->section('content') ?>

<?php
$heroImageUrl = !empty($heroImage)
    ? (strpos($heroImage, 'http') === 0 ? $heroImage : base_url($heroImage))
    : 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1600&q=80';
?>

<section class="about-hero position-relative overflow-hidden">
    <div class="about-hero-bg" style="background-image: url('<?= esc($heroImageUrl) ?>');"></div>
    <div class="about-hero-overlay"></div>
    <div class="container position-relative py-5">
        <div class="row align-items-center min-vh-50 py-4">
            <div class="col-lg-8 text-white">
                <span class="about-chip mb-3 d-inline-flex align-items-center">
                    <i class="fa-solid fa-building me-2"></i>Institucional
                </span>
                <h1 class="display-5 fw-bold mb-3"><?= esc($heroTitle) ?></h1>
                <?php if (!empty($heroSubtitle)): ?>
                    <p class="lead mb-0 text-white-50"><?= esc($heroSubtitle) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<div class="container py-5">
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= site_url('/') ?>" class="text-decoration-none">Início</a></li>
            <li class="breadcrumb-item active" aria-current="page">Sobre Nós</li>
        </ol>
    </nav>

    <section class="row g-4 align-items-start mb-5">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 h-100">
                <div class="d-flex align-items-center mb-3">
                    <div class="about-icon-wrap me-3"><i class="fa-solid fa-landmark"></i></div>
                    <h2 class="fw-bold mb-0"><?= esc($storyTitle) ?></h2>
                </div>

                <?php if (!empty($storyContent)): ?>
                    <div class="about-rich-content lh-lg">
                        <?= $storyContent ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">Conteúdo sobre a empresa ainda não foi preenchido.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 p-4 h-100 about-panel">
                <h3 class="fw-bold mb-4">Nossos números</h3>
                <div class="about-stat mb-4">
                    <div class="about-stat-number"><?= number_format($statsExperience, 0, ',', '.') ?>+</div>
                    <div class="text-muted">anos de experiência</div>
                </div>
                <div class="about-stat mb-4">
                    <div class="about-stat-number"><?= number_format($statsClients, 0, ',', '.') ?>+</div>
                    <div class="text-muted">clientes atendidos</div>
                </div>
                <div class="about-stat">
                    <div class="about-stat-number"><?= number_format($statsProperties, 0, ',', '.') ?>+</div>
                    <div class="text-muted">imóveis anunciados</div>
                </div>
            </div>
        </div>
    </section>

    <section class="row g-4 mb-5">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                <div class="d-flex align-items-center mb-3">
                    <div class="about-icon-wrap me-3"><i class="fa-solid fa-bullseye"></i></div>
                    <h3 class="fw-bold mb-0"><?= esc($missionTitle) ?></h3>
                </div>
                <p class="text-muted mb-0"><?= !empty($missionText) ? esc($missionText) : 'Defina aqui a missão institucional da empresa.' ?></p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
                <div class="d-flex align-items-center mb-3">
                    <div class="about-icon-wrap me-3"><i class="fa-solid fa-eye"></i></div>
                    <h3 class="fw-bold mb-0"><?= esc($visionTitle) ?></h3>
                </div>
                <p class="text-muted mb-0"><?= !empty($visionText) ? esc($visionText) : 'Defina aqui a visão institucional da empresa.' ?></p>
            </div>
        </div>
    </section>

    <section class="card border-0 shadow-sm rounded-4 p-4 p-md-5 mb-5">
        <div class="d-flex align-items-center mb-3">
            <div class="about-icon-wrap me-3"><i class="fa-solid fa-gem"></i></div>
            <h2 class="fw-bold mb-0"><?= esc($valuesTitle) ?></h2>
        </div>

        <?php if (!empty($valuesContent)): ?>
            <div class="about-rich-content lh-lg">
                <?= $valuesContent ?>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">Adicione aqui os valores, diferenciais e compromissos da empresa.</p>
        <?php endif; ?>
    </section>

    <section class="about-cta rounded-4 overflow-hidden">
        <div class="row g-0 align-items-center">
            <div class="col-lg-8 p-4 p-md-5">
                <h2 class="fw-bold text-white mb-3"><?= !empty($ctaTitle) ? esc($ctaTitle) : 'Conte a sua história e aproxime sua marca do cliente' ?></h2>
                <p class="text-white-50 mb-0"><?= !empty($ctaText) ? esc($ctaText) : 'Use o painel administrativo para personalizar esta seção final com uma chamada clara para ação.' ?></p>
            </div>
            <div class="col-lg-4 p-4 p-md-5 text-lg-end">
                <a href="<?= site_url('anuncie') ?>" class="btn btn-light rounded-pill px-4 py-2 fw-semibold me-2 mb-2 mb-lg-0">Anunciar</a>
                <a href="<?= site_url('parceiros') ?>" class="btn btn-outline-light rounded-pill px-4 py-2 fw-semibold">Parceiros</a>
            </div>
        </div>
    </section>
</div>

<style>
.min-vh-50 { min-height: 50vh; }
.about-hero { background: #0f172a; }
.about-hero-bg {
    position: absolute;
    inset: 0;
    background-position: center;
    background-size: cover;
    transform: scale(1.03);
}
.about-hero-overlay {
    position: absolute;
    inset: 0;
    background:
        linear-gradient(135deg, rgba(15, 23, 42, 0.92) 0%, rgba(30, 41, 59, 0.78) 45%, rgba(99, 102, 241, 0.55) 100%),
        radial-gradient(circle at top right, rgba(255, 255, 255, 0.14), transparent 30%);
}
.about-chip {
    padding: 0.55rem 0.95rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.14);
    border: 1px solid rgba(255, 255, 255, 0.18);
    font-size: 0.85rem;
    letter-spacing: 0.03em;
}
.about-icon-wrap {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(var(--primary-rgb, 99, 102, 241), 0.12);
    color: var(--primary-color, #6366f1);
    font-size: 1.1rem;
}
.about-panel {
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
}
.about-stat-number {
    font-size: 2rem;
    line-height: 1;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 0.35rem;
}
.about-rich-content h1,
.about-rich-content h2,
.about-rich-content h3 {
    margin-top: 1.8rem;
    margin-bottom: 0.85rem;
    font-weight: 700;
    color: #0f172a;
}
.about-rich-content p,
.about-rich-content li {
    color: #475569;
}
.about-rich-content ul,
.about-rich-content ol {
    padding-left: 1.35rem;
}
.about-rich-content a {
    color: var(--primary-color, #6366f1);
}
.about-cta {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 55%, var(--primary-color, #6366f1) 100%);
}
@media (max-width: 991.98px) {
    .min-vh-50 { min-height: auto; }
}
</style>

<?= $this->endSection() ?>