<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->renderSection('title') ?> | <?= esc(app_setting('site.name', 'Habitaweb')) ?> Admin</title>
    
    <?php if ($favicon = app_setting('style.favicon_url')): ?>
        <link rel="icon" type="image/x-icon" href="<?= base_url($favicon) ?>">
    <?php endif; ?>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5.2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?= base_url('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    
    <style>
        :root {
            <?php 
                $primary   = app_setting('style.primary_color', '#6366f1');
                $secondary = app_setting('style.secondary_color', '#a855f7');
                $tertiary  = app_setting('style.tertiary_color', '#10b981');

                function hex2rgb($hex) {
                    $hex = str_replace("#", "", $hex);
                    if(strlen($hex) == 3) {
                        $r = hexdec(substr($hex,0,1).substr($hex,0,1));
                        $g = hexdec(substr($hex,1,1).substr($hex,1,1));
                        $b = hexdec(substr($hex,2,1).substr($hex,2,1));
                    } else {
                        $r = hexdec(substr($hex,0,2));
                        $g = hexdec(substr($hex,2,2));
                        $b = hexdec(substr($hex,4,2));
                    }
                    return "$r, $g, $b";
                }
            ?>
            --primary-color: <?= $primary ?>;
            --secondary-color: <?= $secondary ?>;
            --tertiary-color: <?= $tertiary ?>;
            --primary-rgb: <?= hex2rgb($primary) ?>;
            --secondary-rgb: <?= hex2rgb($secondary) ?>;
            --tertiary-rgb: <?= hex2rgb($tertiary) ?>;
            
            --bs-primary: <?= $primary ?>;
            --bs-secondary: <?= $secondary ?>;
            --bs-success: <?= $tertiary ?>;
            
            --primary-gradient: linear-gradient(135deg, <?= $primary ?> 0%, <?= $secondary ?> 100%);
            --secondary-gradient: linear-gradient(135deg, <?= $secondary ?> 0%, <?= $tertiary ?> 100%);
        }

        /* Override basic BS classes to respect dynamic colors */
        .text-primary { color: var(--primary-color) !important; }
        .text-secondary { color: var(--secondary-color) !important; }
        .text-success { color: var(--tertiary-color) !important; }
        
        .bg-primary { background-color: var(--primary-color) !important; }
        .bg-secondary { background-color: var(--secondary-color) !important; }
        .bg-success { background-color: var(--tertiary-color) !important; }

        .btn-primary { background: var(--primary-gradient) !important; border: none !important; }
        .btn-secondary { background: var(--secondary-gradient) !important; border: none !important; }
        .btn-success { background-color: var(--tertiary-color) !important; border: none !important; }
        /* Fix Z-Index for Dropdowns */
        .dropdown-menu { z-index: 10001 !important; }
        .top-navbar-premium { z-index: 1080 !important; position: relative; }
        .sidebar { z-index: 1090 !important; }
        .main-content { overflow: visible !important; position: relative; z-index: 1; }
    </style>

    <?= $this->renderSection('styles') ?>
</head>
<body>

    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <a href="<?= site_url('admin') ?>" class="sidebar-brand text-decoration-none">
                <?php if ($logo = app_setting('style.logo_url')): ?>
                    <img src="<?= base_url($logo) ?>" alt="Logo" height="35" class="object-fit-contain">
                <?php else: ?>
                    <i class="fa-solid fa-house-chimney-user"></i>
                    <span><?= esc(app_setting('site.name', 'Habitaweb')) ?></span>
                <?php endif; ?>
            </a>
        </div>
        
        <div class="d-flex flex-column flex-grow-1 overflow-auto">
            <a href="<?= site_url('admin') ?>" class="nav-link <?= uri_string() == 'admin' ? 'active' : '' ?>">
                <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
            
            <div class="sidebar-category">Gestão</div>
            
            <a href="<?= site_url('admin/properties') ?>" class="nav-link <?= strpos(uri_string(), 'properties') !== false ? 'active' : '' ?>">
                <i class="fa-solid fa-house"></i> Imóveis
            </a>
            <a href="<?= site_url('admin/leads') ?>" class="nav-link <?= url_is('admin/leads*') ? 'active' : '' ?>">
                <i class="fa-solid fa-users-gear"></i> Meus Leads
            </a>
            
            <a href="<?= site_url('admin/clients') ?>" class="nav-link <?= url_is('admin/clients*') ? 'active' : '' ?>">
                <i class="fa-solid fa-address-book"></i> Meus Clientes
            </a>

            <?php if (auth()->user()->can('imobiliaria.manage_team')): ?>
            <a href="<?= site_url('admin/team') ?>" class="nav-link <?= url_is('admin/team*') ? 'active' : '' ?>">
                <i class="fa-solid fa-users"></i> Minha Equipe
            </a>
            <?php endif; ?>
            
            <?php if (!auth()->user()->inGroup('superadmin')): ?>
             <a href="<?= site_url('admin/promotions') ?>" class="nav-link">
                <i class="fa-solid fa-rocket"></i> Destaques
            </a>
            
            <a href="<?= site_url('admin/subscription') ?>" class="nav-link <?= strpos(uri_string(), 'subscription') !== false ? 'active' : '' ?>">
                <i class="fa-solid fa-credit-card"></i> Minha Assinatura
            </a>
            
            <a href="<?= site_url('admin/payments') ?>" class="nav-link <?= strpos(uri_string(), 'admin/payments') !== false ? 'active' : '' ?>">
                <i class="fa-solid fa-receipt"></i> Pagamentos
            </a>
            <?php endif; ?>
            
            <div class="sidebar-category">Sistema</div>
            
            <?php if (auth()->user()->inGroup('admin') || auth()->user()->inGroup('superadmin')): ?>
            <a href="<?= site_url('admin/curation') ?>" class="nav-link <?= strpos(uri_string(), 'curation') !== false ? 'active' : '' ?>">
                <i class="fa-solid fa-list-check"></i> Curadoria
            </a>
            
            <a href="<?= site_url('admin/accounts') ?>" class="nav-link">
                <i class="fa-solid fa-briefcase"></i> Contas
            </a>
             <a href="<?= site_url('admin/users') ?>" class="nav-link">
                <i class="fa-solid fa-user-gear"></i> Usuários
            </a>
             <a href="<?= site_url('admin/plans') ?>" class="nav-link <?= strpos(uri_string(), 'plans') !== false ? 'active' : '' ?>">
                <i class="fa-solid fa-tags"></i> Planos
            </a>
            
            <a href="<?= site_url('admin/coupons') ?>" class="nav-link <?= strpos(uri_string(), 'coupons') !== false ? 'active' : '' ?>">
                <i class="fa-solid fa-ticket"></i> Cupons
            </a>

            <a href="<?= site_url('admin/packages') ?>" class="nav-link <?= strpos(uri_string(), 'packages') !== false ? 'active' : '' ?>">
                <i class="fa-solid fa-box-open"></i> Pacotes Turbo
            </a>

            <a href="<?= site_url('admin/payment-gateways') ?>" class="nav-link <?= strpos(uri_string(), 'payment-gateways') !== false ? 'active' : '' ?>">
                <i class="fa-solid fa-credit-card"></i> Gateways
            </a>
            <?php endif; ?>

            <a href="<?= site_url('admin/api-keys') ?>" class="nav-link <?= strpos(uri_string(), 'api-keys') !== false ? 'active' : '' ?>">
                <i class="fa-solid fa-key"></i> Chaves de API
            </a>

            <?php if (auth()->user()->inGroup('superadmin')): ?>
             <a href="<?= site_url('admin/settings') ?>" class="nav-link <?= strpos(uri_string(), 'settings') !== false ? 'active' : '' ?>">
                <i class="fa-solid fa-cogs"></i> Configurações
            </a>
            <?php endif; ?>
        </div>
        
        <div class="mt-auto p-3">
            <a href="<?= site_url('admin/logout') ?>" class="btn btn-outline-danger w-100 rounded-3">
                <i class="fa-solid fa-right-from-bracket me-2"></i> Sair
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <header class="top-navbar-premium animate-fade-in">
            <?php 
                $acc = null;
                $displayName = auth()->user()->username; // Fallback
                
                // Tenta buscar o nome da conta vinculada (Imobiliária/Corretor)
                if ($accountId = auth()->user()->account_id) {
                    // Cache simples para evitar query repetida se já foi pego em outro lugar, 
                    // mas como é model->find via ID, o CI4 já otimiza se tiver cache de model.
                    // Aqui vamos buscar direto.
                    $acc = model('App\Models\AccountModel')->find($accountId);
                    
                    if ($acc) {
                        $accName = is_object($acc) ? $acc->nome : ($acc['nome'] ?? '');
                        if (!empty($accName)) {
                            $displayName = $accName;
                        }
                    }
                }
            ?>
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-light rounded-circle shadow-sm" id="sidebarToggle">
                    <i class="fa-solid fa-bars-staggered"></i>
                </button>
                <div class="d-none d-md-block ms-2">
                    <h5 class="fw-bold mb-0 text-dark"><?= $this->renderSection('page_title') ?></h5>
                    <p class="text-muted small mb-0">Bem-vindo de volta, <strong><?= esc($displayName) ?></strong></p>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <div class="dropdown me-3 d-none d-sm-block">
                    <button class="btn btn-light rounded-circle position-relative p-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="notifyBtn">
                        <i class="fa-regular fa-bell"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-light d-none" id="notifyBadge" style="padding: 4px; border-width: 2px !important;">
                            <span class="visually-hidden">Notificações</span>
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-0 mt-2 animate-fade-in" style="width: 320px; max-height: 400px; overflow-y: auto;">
                        <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-light rounded-top-4">
                            <h6 class="mb-0 fw-bold text-dark"><i class="fa-regular fa-bell me-2"></i>Notificações</h6>
                            <button class="btn btn-sm btn-link text-decoration-none text-primary fw-bold p-0" onclick="markAllNotificationsRead()" style="font-size: 0.75rem;">
                                Limpar tudo
                            </button>
                        </div>
                        <div id="notificationList" class="list-group list-group-flush">
                            <div class="text-center py-5 text-muted">
                                <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                                <p class="small mb-0">Carregando...</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="dropdown profile-dropdown">
                    <button class="btn btn-white border-0 d-flex align-items-center gap-2 p-1 pe-3 rounded-pill bg-white shadow-sm" type="button" data-bs-toggle="dropdown">
                        <?php if ($acc && (is_object($acc) ? $acc->logo : ($acc['logo'] ?? null))): ?>
                            <img src="<?= base_url(is_object($acc) ? $acc->logo : $acc['logo']) ?>" class="rounded-circle object-fit-cover" width="35" height="35">
                        <?php else: ?>
                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($displayName) ?>&background=6366f1&color=fff" class="rounded-circle" width="35">
                        <?php endif; ?>
                        <div class="text-start d-none d-sm-block">
                            <div class="fw-bold small lh-1"><?= esc($displayName) ?></div>
                            <small class="text-muted" style="font-size: 0.7rem;">
                                <?php 
                                    $role = auth()->user()->getGroups()[0] ?? 'admin';
                                    echo lang('Auth.role_' . $role);
                                ?>
                            </small>
                        </div>
                        <i class="fa-solid fa-chevron-down small text-muted ms-1"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-2 mt-2">
                        <li><a class="dropdown-item rounded-3 py-2" href="<?= site_url('admin/profile') ?>"><i class="fa-solid fa-user-circle me-2 text-muted"></i> Meu Perfil / Conta</a></li>
                        <?php if (auth()->user()->inGroup('superadmin')): ?>
                        <li><a class="dropdown-item rounded-3 py-2" href="<?= site_url('admin/settings') ?>"><i class="fa-solid fa-cog me-2 text-muted"></i> Configurações</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item rounded-3 py-2 text-danger" href="<?= site_url('admin/logout') ?>"><i class="fa-solid fa-sign-out-alt me-2"></i> Sair</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <div class="animate-fade-in" style="animation-delay: 0.1s">
            <!-- Flash Messages Removed: Now using SweetAlert2 via JS -->
            
            <!-- Page Content -->
            <?= $this->renderSection('content') ?>
        </div>
    </div>

    <!-- Mobile Navigation Toggle -->
    <div class="mobile-nav-toggle d-lg-none" id="mobileToggle">
        <i class="fa-solid fa-bars-staggered"></i>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/pt-BR.js"></script>
    <script src="<?= base_url('assets/js/jquery.maskMoney.min.js') ?>"></script>
    <script>
        // Sidebar Toggle Logic
        $('#sidebarToggle, #mobileToggle, .mobile-nav-toggle').click(function() {
            if ($(window).width() > 992) {
                // Desktop: Toggle collapse class on body
                $('body').toggleClass('sidebar-collapsed');
            } else {
                // Mobile: Toggle active class on sidebar
                $('.sidebar').toggleClass('active');
            }
        });

        // Close sidebar on click outside on mobile
        $(document).on('click', function(e) {
            if ($(window).width() <= 992) {
                if (!$(e.target).closest('.sidebar').length && 
                    !$(e.target).closest('#sidebarToggle').length && 
                    !$(e.target).closest('#mobileToggle').length &&
                    !$(e.target).closest('.mobile-nav-toggle').length) {
                    $('.sidebar').removeClass('active');
                }
            }
        });

        // Global SweetAlert Helpers
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });

        // Função Global para Confirmações via SweetAlert2 (AJAX)
        function confirmAction(url, method, title, text = "Esta ação não pode ser desfeita facilmente.", extraData = {}) {
            Swal.fire({
                title: title,
                text: text,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#344767',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Sim, proceder!',
                cancelButtonText: 'Cancelar',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return $.ajax({
                        url: url,
                        method: method,
                        data: {
                            '<?= csrf_token() ?>': '<?= csrf_hash() ?>',
                            '_method': method,
                            ...extraData
                        },
                        dataType: 'json'
                    }).done(function(response) {
                        if (!response.success) {
                            Swal.showValidationMessage(`Erro: ${response.message}`);
                        }
                        return response;
                    }).fail(function(xhr) {
                        Swal.showValidationMessage(`Erro de conexão: ${xhr.statusText}`);
                    });
                },
                allowOutsideClick: () => !Swal.isLoading()
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Sucesso!',
                        text: result.value.message,
                        confirmButtonColor: '#344767'
                    }).then(() => {
                        window.location.reload();
                    });
                }
            });
        }

        <?php if (session()->has('message')): ?>
            Swal.fire({
                icon: 'success',
                title: 'Sucesso!',
                text: '<?= session('message') ?>',
                confirmButtonColor: '#344767',
                input: undefined // Garante que nenhum select apareça
            });
        <?php endif; ?>

        <?php if (session()->has('error')): ?>
            Swal.fire({
                icon: 'error',
                title: 'Ops!',
                text: '<?= session('error') ?>',
                confirmButtonColor: '#344767'
            });
        <?php endif; ?>

        <?php if (session()->has('errors')): ?>
            Swal.fire({
                icon: 'error',
                title: 'Erros de Validação',
                html: '<?= implode("<br>", session('errors')) ?>',
                confirmButtonColor: '#344767'
            });
        <?php endif; ?>
        // Notification Logic
        function loadNotifications() {
            $.get('<?= site_url("admin/notifications") ?>', function(data) {
                const list = $('#notificationList');
                const badge = $('#notifyBadge');
                
                list.empty();
                
                if (data.unread_count > 0) {
                    badge.text(data.unread_count).removeClass('d-none');
                } else {
                    badge.addClass('d-none');
                }

                if (data.notifications.length === 0) {
                    list.html(`
                        <div class="text-center py-5 text-muted">
                            <i class="fa-regular fa-bell-slash fa-2x mb-3 text-secondary opacity-50"></i>
                            <p class="small mb-0 fw-bold">Tudo limpo!</p>
                            <span class="small opacity-75">Nenhuma notificação nova.</span>
                        </div>
                    `);
                    return;
                }

                data.notifications.forEach(function(notif) {
                    const item = $(`
                        <div class="list-group-item list-group-item-action p-3 border-bottom-0 border-top bg-white notification-item" onclick="markRead(${notif.id}, '${notif.link || ''}')" style="cursor: pointer; transition: background 0.2s;">
                            <div class="d-flex align-items-start gap-3">
                                <div class="flex-shrink-0 mt-1">
                                    <i class="${notif.icon_class} fa-lg"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-1 small fw-bold text-dark d-flex justify-content-between">
                                        ${notif.title}
                                        <span class="text-muted fw-normal" style="font-size: 0.65rem;">${notif.time_ago}</span>
                                    </h6>
                                    <p class="text-muted small mb-0 lh-sm pe-2">${notif.message}</p>
                                </div>
                            </div>
                        </div>
                    `);
                    
                    item.hover(function(){ $(this).addClass('bg-light'); }, function(){ $(this).removeClass('bg-light'); });
                    list.append(item);
                });
            }).fail(function() {
                console.log('Erro ao carregar notificações.');
            });
        }

        window.markAllNotificationsRead = function() {
            $.post('<?= site_url("admin/notifications/mark-all-read") ?>', {<?= csrf_token() ?>: '<?= csrf_hash() ?>'}, function() {
                loadNotifications();
                Toast.fire({ icon: 'success', title: 'Notificações limpas!' });
            });
        };

        window.markRead = function(id, link) {
            $.post(`<?= site_url("admin/notifications/mark-read") ?>/${id}`, {<?= csrf_token() ?>: '<?= csrf_hash() ?>'}, function() {
                if(link && link !== 'null' && link !== '') {
                    window.location.href = link;
                } else {
                    loadNotifications();
                }
            });
        };

        // Load on start and every 60s
        $(document).ready(function() {
            // Init Select2 Globally (exceto em SweetAlerts)
            $('select:not(.no-select2):not(.swal2-container select)').select2({
                theme: 'bootstrap-5',
                width: '100%',
                language: 'pt-BR',
                placeholder: 'Selecione',
                allowClear: false // Evita bugs em selects required
            });
            
            // Re-init Select2 quando novos elementos são adicionados ao DOM (exceto SweetAlert)
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes.length) {
                        $(mutation.addedNodes).find('select:not(.no-select2):not(.swal2-container select)').each(function() {
                            if (!$(this).hasClass('select2-hidden-accessible')) {
                                $(this).select2({
                                    theme: 'bootstrap-5',
                                    width: '100%',
                                    language: 'pt-BR',
                                    placeholder: 'Selecione',
                                    allowClear: false
                                });
                            }
                        });
                    }
                });
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            loadNotifications();
            setInterval(loadNotifications, 60000);
        });

        // Money Mask
        if ($.fn.maskMoney) {
            $(".double3").maskMoney({
                thousands: '.',
                decimal: ',',
                allowZero: true,
                allowNegative: false,
                precision: 2
            });
        }
    </script>
    <?= $this->renderSection('modals') ?>
    <?= $this->renderSection('scripts') ?>
</body>
</html>
