<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= $this->renderSection('meta_description') ?: esc(app_setting('seo.description', 'Portal imobiliário')) ?>">
    <meta name="keywords" content="<?= esc(app_setting('seo.keywords', 'imoveis')) ?>">
    <title><?= $this->renderSection('meta_title') ?: ($this->renderSection('title') . ' - ' . esc(app_setting('seo.title', 'Habitaweb'))) ?></title>
    
    <?php if ($favicon = app_setting('style.favicon_url')): ?>
        <link rel="icon" type="image/x-icon" href="<?= base_url($favicon) ?>">
    <?php endif; ?>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5.2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom Public CSS -->
    <link rel="stylesheet" href="<?= base_url('assets/css/public.css') ?>">
    
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    
    <?php 
        $primary   = app_setting('style.primary_color', '#6366f1');
        $secondary = app_setting('style.secondary_color', '#a855f7');
        
        // Use declared function in sys_helper
        $primaryRgb = hexToRgb($primary);
        $secondaryRgb = hexToRgb($secondary);
    ?>
    <style>
        :root { 
            --primary-color: <?= $primary ?>;
            --primary-rgb: <?= $primaryRgb ?>;
            --secondary-color: <?= $secondary ?>;
            --secondary-rgb: <?= $secondaryRgb ?>;
            --bs-primary: <?= $primary ?>; 
            --bs-link-color: <?= $primary ?>; 
            
            --primary-gradient: linear-gradient(135deg, <?= $primary ?> 0%, <?= $secondary ?> 100%);
            --secondary-gradient: linear-gradient(135deg, <?= $secondary ?> 0%, #10b981 100%);
        }
        .bg-primary-soft { background-color: rgba(<?= $primaryRgb ?>, 0.1) !important; }
        .text-primary { color: var(--primary-color) !important; }
        .btn-primary { background-color: var(--primary-color) !important; border-color: var(--primary-color) !important; border-radius: 50px !important; }
        .btn-outline-primary { color: var(--primary-color) !important; border-color: var(--primary-color) !important; border-radius: 50px !important; }
        .btn-outline-primary:hover { background-color: var(--primary-color) !important; color: #fff !important; }
        
        /* Premium Gradient Utilities */
        .text-primary-gradient {
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Select2 Premium Styling - Balanced & Clean */
        .select2-container--bootstrap-5 .select2-selection {
            border: none !important;
            background-color: transparent !important;
            box-shadow: none !important;
            padding: 0 !important;
            height: 48px !important; /* Balanced height */
            display: flex !important;
            align-items: center !important;
            transition: all 0.2s ease;
        }
        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
            padding: 0 !important;
            font-size: 16px !important; /* Professional size */
            font-weight: 600 !important;
            color: var(--text-dark) !important;
            line-height: normal !important;
        }
        .select2-container--bootstrap-5 .select2-dropdown {
            border: 1px solid #f0f0f0 !important;
            border-radius: 20px !important; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.08) !important;
            overflow: hidden;
            margin-top: 10px;
            padding: 8px;
            background: #fff;
        }
        
        .select2-search--dropdown {
            padding: 0 0 10px 0 !important;
        }
        .select2-container--bootstrap-5 .select2-search__field {
            border: 1px solid #f0f0f0 !important;
            border-radius: 12px !important;
            padding: 8px 15px !important;
            font-size: 14px !important;
            background-color: #fafafa !important;
        }
        
        .select2-container--bootstrap-5 .select2-results__option {
            padding: 12px 16px !important;
            border-radius: 12px !important;
            font-size: 15px !important;
            margin-bottom: 4px;
            color: var(--text-dark) !important;
            transition: all 0.15s ease;
        }
        .select2-container--bootstrap-5 .select2-results__option--highlighted[aria-selected] {
            background-color: var(--primary-color) !important;
            color: #fff !important;
        }
        .select2-container--bootstrap-5 .select2-results__option--selected {
            background-color: var(--bg-light) !important;
            color: var(--primary-color) !important;
            font-weight: 700 !important;
        }
        
        /* Specific adjustments for the floating search bar */
        .search-container-floating .select2-container {
            width: 100% !important;
        }
        .search-container-floating .search-item {
            height: 90px; /* Standardize height */
            padding: 10px 20px !important;
            display: flex;
            flex-direction: column;
            justify-content: center;
            border-right: 1px solid #f0f0f0; /* Subtle divider */
        }
        .search-container-floating .search-item:last-of-type {
            border-right: none;
        }
        .search-container-floating .search-item.flex-large {
            flex: 1.4 !important;
        }
        .search-container-floating .search-item label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            opacity: 0.5;
            margin-bottom: 2px;
            white-space: nowrap;
        }
        .btn-search-round {
            width: 58px !important;
            height: 58px !important;
            font-size: 20px !important;
            flex-shrink: 0;
            background: var(--primary-gradient);
            border: none;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(var(--primary-rgb), 0.3);
        }
        .btn-search-round:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(var(--primary-rgb), 0.4);
        }
        .search-container-floating {
            max-width: 1050px !important;
            border-radius: 50px !important;
            width: 95% !important;
            background: #fff;
            padding: 0 10px;
            display: flex;
            align-items: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.05);
        }
    </style>
    <?= $this->renderSection('styles') ?>
</head>
<body class="bg-light">

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom fixed-top py-3 shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold fs-4 d-flex align-items-center gap-2" href="<?= site_url('/') ?>">
                <?php if ($logo = app_setting('style.logo_url')): ?>
                    <img src="<?= base_url($logo) ?>" alt="Logo" height="40" class="object-fit-contain">
                <?php else: ?>
                    <i class="fa-solid fa-map-location-dot text-primary"></i> 
                    <span><?= esc(app_setting('site.name', 'Habitaweb')) ?></span>
                <?php endif; ?>
            </a>
            
            <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarContent">
                <ul class="navbar-nav mx-auto mb-2 mb-lg-0 gap-lg-3">
                    <li class="nav-item">
                        <a class="nav-link fw-500" href="<?= site_url('/') ?>">Início</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-500" href="<?= site_url('imoveis/venda') ?>">Comprar</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-500" href="<?= site_url('imoveis/aluguel') ?>">Alugar</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-500" href="<?= site_url('parceiros') ?>">Parceiros</a>
                    </li>
                </ul>
                
                <div class="d-flex align-items-center gap-3">
                    <a href="<?= site_url('anuncie') ?>" class="btn btn-outline-primary rounded-pill px-4 fw-bold d-none d-md-block">
                        Anunciar Grátis
                    </a>

                    <?php if (auth()->loggedIn()): ?>
                        <a href="<?= site_url('meus-favoritos') ?>" class="text-dark position-relative me-2" title="Meus Favoritos">
                            <i class="fa-regular fa-heart fa-lg"></i>
                        </a>
                        <a href="<?= site_url('admin') ?>" class="btn btn-primary rounded-pill px-4">
                            Meu Painel
                        </a>
                    <?php else: ?>
                        <a href="<?= site_url('login') ?>" class="text-dark text-decoration-none fw-bold me-3">Entrar</a>
                        <a href="<?= site_url('register') ?>" class="btn btn-primary rounded-pill px-4">Anunciar</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
    
    <div style="margin-top: 80px;"></div>

    <!-- Main Content -->
    <main>
        <?= $this->renderSection('content') ?>
    </main>
    
    <!-- Footer -->
    <footer class="bg-white border-top py-5 mt-5">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4">
                     <h5 class="fw-bold mb-3 d-flex align-items-center gap-2">
                        <i class="fa-solid fa-map-location-dot text-primary"></i> <?= esc(app_setting('site.name', 'Habitaweb')) ?>
                    </h5>
                    <p class="text-muted small">
                        O maior portal imobiliário da região. Encontre seu novo lar compra venda ou aluguel com segurança e agilidade.
                    </p>
                    <div class="d-flex gap-3 mt-4">
                        <a href="#" class="text-muted"><i class="fa-brands fa-instagram fa-lg"></i></a>
                        <a href="#" class="text-muted"><i class="fa-brands fa-facebook fa-lg"></i></a>
                        <a href="#" class="text-muted"><i class="fa-brands fa-whatsapp fa-lg"></i></a>
                    </div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <h6 class="fw-bold text-uppercase small text-muted mb-3">Soluções</h6>
                    <ul class="list-unstyled small d-flex flex-column gap-2 mb-0">
                        <li><a href="#" class="text-decoration-none text-secondary">Anunciar</a></li>
                        <li><a href="#" class="text-decoration-none text-secondary">Planos</a></li>
                        <li><a href="#" class="text-decoration-none text-secondary">Para Corretores</a></li>
                        <li><a href="#" class="text-decoration-none text-secondary">Para Imobiliárias</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-top mt-5 pt-4 text-center text-muted small">
                © <?= date('Y') ?> <?= esc(app_setting('site.name', 'Habitaweb')) ?>. Todos os direitos reservados.
            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?= view('Scripts/public_layout') ?>
    <?= $this->renderSection('scripts') ?>
</body>
</html>
