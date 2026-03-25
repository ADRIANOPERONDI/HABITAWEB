<?= $this->extend('Layouts/public') ?>

<?= $this->section('title') ?>Termos de Uso<?= $this->endSection() ?>

<?= $this->section('meta_description') ?>Leia os Termos de Uso do <?= esc(app_setting('seo.title', 'Portal Imobiliário')) ?>.<?= $this->endSection() ?>

<?= $this->section('content') ?>

<!-- Hero mínimo -->
<div style="background: linear-gradient(135deg, var(--primary-color, #6366f1) 0%, var(--secondary-color, #a855f7) 100%);" class="py-5">
    <div class="container py-3 text-white text-center">
        <h1 class="fw-bold mb-2">Termos de Uso</h1>
        <p class="mb-0 opacity-75">Última atualização: <?= date('d/m/Y') ?></p>
    </div>
</div>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">

            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= site_url('/') ?>" class="text-decoration-none">Início</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Termos de Uso</li>
                </ol>
            </nav>

            <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5">
                <?php if (!empty($conteudo)): ?>
                    <div class="legal-content lh-lg">
                        <?= $conteudo ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fa-solid fa-file-lines fa-3x text-muted mb-3 d-block"></i>
                        <h5 class="text-muted">Conteúdo em elaboração</h5>
                        <p class="text-muted small">Os Termos de Uso serão publicados em breve.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="text-center mt-4">
                <a href="<?= site_url('privacidade') ?>" class="btn btn-outline-secondary rounded-pill px-4">
                    <i class="fa-solid fa-shield-halved me-2"></i>Ver Política de Privacidade
                </a>
            </div>

        </div>
    </div>
</div>

<style>
.legal-content h1, .legal-content h2, .legal-content h3 { margin-top: 2rem; margin-bottom: 1rem; font-weight: 700; }
.legal-content h2 { font-size: 1.4rem; border-bottom: 2px solid #f1f1f1; padding-bottom: .5rem; }
.legal-content h3 { font-size: 1.1rem; color: #344767; }
.legal-content p { color: #495057; }
.legal-content ul, .legal-content ol { color: #495057; padding-left: 1.5rem; }
.legal-content li { margin-bottom: .5rem; }
.legal-content strong { color: #344767; }
.legal-content a { color: var(--primary-color, #6366f1); }
</style>

<?= $this->endSection() ?>
