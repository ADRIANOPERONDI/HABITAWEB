<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= $this->renderSection('meta_description') ?: esc(app_setting('seo.description', 'Portal imobiliário')) ?>">
    <meta name="keywords" content="<?= esc(app_setting('seo.keywords', 'imoveis')) ?>">
    <title>
        <?php 
            $pageTitle = $this->renderSection('title');
            $siteName = app_setting('seo.title', 'Habitaweb');
            $tagline = app_setting('seo.tagline', 'Encontre seu lugar');
            
            if ($pageTitle) {
                echo $pageTitle . ' - ' . $siteName;
            } else {
                echo $siteName . ' - ' . $tagline;
            }
        ?>
    </title>
    
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
            --primary-color: <?= esc($primary) ?>;
            --primary-rgb: <?= esc($primaryRgb) ?>;
            --secondary-color: <?= esc($secondary) ?>;
            --secondary-rgb: <?= esc($secondaryRgb) ?>;
            --bs-primary: <?= esc($primary) ?>; 
            --bs-link-color: <?= esc($primary) ?>; 
            
            --primary-gradient: linear-gradient(135deg, <?= esc($primary) ?> 0%, <?= esc($secondary) ?> 100%);
            --secondary-gradient: linear-gradient(135deg, <?= esc($secondary) ?> 0%, #10b981 100%);
        }
        .bg-primary-soft { background-color: rgba(<?= esc($primaryRgb) ?>, 0.1) !important; }
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
                    <img src="<?= base_url($logo) ?>" alt="Logo" height="<?= app_setting('style.logo_height', 70) ?>" class="object-fit-contain">
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
    <footer class="py-5 mt-5" style="background-color: #1a1a1a; color: rgba(255,255,255,0.7);">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <div class="footer-brand mb-4">
                        <?php if ($logo = app_setting('style.logo_url')): ?>
                            <img src="<?= base_url($logo) ?>" alt="Logo" height="40" class="object-fit-contain brightness-0 invert">
                        <?php endif; ?>
                        <span class="fs-4 fw-bold ms-2 text-white"><?= app_setting('seo.title', 'Habitaweb') ?></span>
                    </div>
                    <p class="opacity-75 mb-4">
                        <?= app_setting('footer.description', 'O portal imobiliário mais completo da região. Conectando pessoas aos seus sonhos.') ?>
                    </p>
                    <div class="footer-social d-flex gap-3">
                        <a href="<?= app_setting('social.facebook', '#') ?>" class="text-white opacity-75"><i class="fa-brands fa-facebook-f fs-5"></i></a>
                        <a href="<?= app_setting('social.instagram', '#') ?>" class="text-white opacity-75"><i class="fa-brands fa-instagram fs-5"></i></a>
                        <a href="<?= app_setting('social.whatsapp', '#') ?>" class="text-white opacity-75"><i class="fa-brands fa-whatsapp fs-5"></i></a>
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-4 mb-4 mb-md-0">
                    <h6 class="fw-bold text-white mb-4">Navegação</h6>
                    <ul class="list-unstyled d-flex flex-column gap-2 opacity-75">
                        <li><a href="<?= site_url('imoveis') ?>" class="text-white text-decoration-none">Comprar</a></li>
                        <li><a href="<?= site_url('imoveis?tipo_negocio=ALUGUEL') ?>" class="text-white text-decoration-none">Alugar</a></li>
                        <li><a href="<?= site_url('anuncie') ?>" class="text-white text-decoration-none">Anunciar</a></li>
                        <li><a href="<?= site_url('parceiros') ?>" class="text-white text-decoration-none">Parceiros</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-4 mb-4 mb-md-0">
                    <h6 class="fw-bold text-white mb-4">Institucional</h6>
                    <ul class="list-unstyled d-flex flex-column gap-2 opacity-75">
                        <li><a href="#" class="text-white text-decoration-none">Sobre Nós</a></li>
                        <li><a href="#" class="text-white text-decoration-none">Política de Privacidade</a></li>
                        <li><a href="#" class="text-white text-decoration-none">Termos de Uso</a></li>
                    </ul>
                </div>

                <div class="col-lg-4 col-md-4">
                    <h6 class="fw-bold text-white mb-4">Contato</h6>
                    <ul class="list-unstyled d-flex flex-column gap-3 opacity-75">
                        <li class="d-flex align-items-start gap-3">
                            <i class="fa-solid fa-location-dot mt-1"></i>
                            <span><?= app_setting('footer.address', 'Av. Principal, 123 - Centro') ?></span>
                        </li>
                        <li class="d-flex align-items-center gap-3">
                            <i class="fa-solid fa-phone"></i>
                            <span><?= app_setting('site.phone', '(00) 0000-0000') ?></span>
                        </li>
                        <li class="d-flex align-items-center gap-3">
                            <i class="fa-solid fa-envelope"></i>
                            <span><?= app_setting('site.email', 'contato@habitaweb.com.br') ?></span>
                        </li>
                    </ul>
                </div>
            </div>
            
            <hr class="my-5 opacity-25">
            
            <div class="footer-bottom d-md-flex justify-content-between align-items-center opacity-75 small">
                <p class="mb-md-0">
                    <?= app_setting('site.copyright', '&copy; ' . date('Y') . ' Habitaweb. Todos os direitos reservados.') ?>
                </p>
                <div class="d-flex gap-4">
                    <span>Feito com <i class="fa-solid fa-heart text-danger"></i> pela Equipe</span>
                </div>
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
