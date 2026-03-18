<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso - <?= esc(app_setting('site.name', 'Habitaweb')) ?></title>
    
    <?php if ($favicon = app_setting('style.favicon_url')): ?>
        <link rel="icon" type="image/x-icon" href="<?= base_url($favicon) ?>">
    <?php endif; ?>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5.2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            <?php 
                $primary   = app_setting('style.primary_color', '#6366f1');
                $secondary = app_setting('style.secondary_color', '#a855f7');
                $tertiary  = app_setting('style.tertiary_color', '#10b981');
            ?>
            --primary-color: <?= $primary ?>;
            --secondary-color: <?= $secondary ?>;
            --tertiary-color: <?= $tertiary ?>;
            --primary-gradient: linear-gradient(135deg, <?= $primary ?> 0%, <?= $secondary ?> 100%);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            margin: 0;
            overflow-x: hidden;
        }

        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        /* Lado Esquerdo: Marketing */
        .marketing-side {
            background: var(--primary-gradient);
            color: white;
            padding: 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
            min-height: 100vh;
        }

        .marketing-side::before {
            content: "";
            position: absolute;
            top: -10%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            filter: blur(80px);
        }

        .marketing-side::after {
            content: "";
            position: absolute;
            bottom: -5%;
            left: -5%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
            filter: blur(60px);
        }

        .cta-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            transition: all 0.3s ease;
            text-decoration: none;
            color: white;
            display: block;
        }

        .cta-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255,255,255,0.4);
            color: white;
        }

        .icon-box {
            width: 50px;
            height: 50px;
            background: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: 1.5rem;
        }

        /* Lado Direito: Formulário */
        .form-side {
            padding: 80px;
            background: white;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-form-container {
            max-width: 420px;
            width: 100%;
            margin: 0 auto;
        }

        .form-control {
            border-radius: 12px;
            padding: 14px 18px;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            transition: all 0.3s;
        }

        .form-control:focus {
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            border-color: var(--primary-color);
            background: #fff;
        }

        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
            padding: 16px !important;
            border-radius: 12px !important;
            font-weight: 700 !important;
            font-family: 'Outfit', sans-serif;
            box-shadow: 0 10px 20px -5px rgba(99, 102, 241, 0.4) !important;
            transition: all 0.3s ease !important;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px -10px rgba(99, 102, 241, 0.6) !important;
        }

        .btn-outline {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px;
            color: #64748b;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-outline:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #1e293b;
        }

        .brand-logo {
            height: 80px;
            margin-bottom: 40px;
        }

        @media (max-width: 991px) {
            .marketing-side { display: none; }
            .form-side { padding: 40px 20px; }
        }
    </style>
</head>
<body>

    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Coluna de Marketing -->
            <div class="col-lg-7 marketing-side">
                <div class="mb-5">
                    <h1 class="display-4 fw-bold mb-3" style="font-family: 'Outfit', sans-serif;"><?= lang('App.login_marketing_title') ?></h1>
                    <p class="lead opacity-75 mb-5"><?= lang('App.login_marketing_desc') ?></p>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <a href="<?= site_url('anuncie') ?>" class="cta-card">
                            <div class="icon-box">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            <h4 class="fw-bold mb-2"><?= lang('App.login_cta_anuncie_title') ?></h4>
                            <p class="small opacity-75 mb-0"><?= lang('App.login_cta_anuncie_desc') ?></p>
                        </a>
                    </div>
                    <div class="col-md-6">
                        <a href="<?= site_url('anuncie') ?>" class="cta-card">
                            <div class="icon-box">
                                <i class="fas fa-rocket"></i>
                            </div>
                            <h4 class="fw-bold mb-2"><?= lang('App.login_cta_turbine_title') ?></h4>
                            <p class="small opacity-75 mb-0"><?= lang('App.login_cta_turbine_desc') ?></p>
                        </a>
                    </div>
                </div>

                <div class="mt-5 d-flex align-items-center gap-4 opacity-75 small">
                    <div class="d-flex align-items-center"><i class="fas fa-check-circle me-2"></i> <?= lang('App.login_feature_no_contract') ?></div>
                    <div class="d-flex align-items-center"><i class="fas fa-check-circle me-2"></i> <?= lang('App.login_feature_support') ?></div>
                    <div class="d-flex align-items-center"><i class="fas fa-check-circle me-2"></i> <?= lang('App.login_feature_seo') ?></div>
                </div>
            </div>

            <!-- Coluna do Formulário -->
            <div class="col-lg-5 form-side">
                <div class="login-form-container">
                    
                    <?php if ($logo = app_setting('style.logo_url')): ?>
                        <img src="<?= base_url($logo) ?>" alt="Logo" class="brand-logo object-fit-contain">
                    <?php else: ?>
                        <div class="h2 fw-bold mb-4" style="color: var(--primary-color);">Habita<span class="text-dark">web</span></div>
                    <?php endif; ?>

                    <h2 class="fw-bold mb-1" style="font-family: 'Outfit', sans-serif;"><?= lang('App.login_welcome') ?></h2>
                    <p class="text-muted mb-4"><?= lang('App.login_subtitle') ?></p>

                    <?php if (session('error')) : ?>
                        <div class="alert alert-danger shadow-sm mb-4 border-0 rounded-3">
                            <i class="fa-solid fa-circle-exclamation me-2"></i> <?= session('error') ?>
                        </div>
                    <?php endif ?>

                    <?php if (session('message')) : ?>
                        <div class="alert alert-success shadow-sm mb-4 border-0 rounded-3">
                            <i class="fa-solid fa-check-circle me-2"></i> <?= session('message') ?>
                        </div>
                    <?php endif ?>

                    <form action="<?= base_url('admin/login') ?>" method="post">
                        <?= csrf_field() ?>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted text-uppercase"><?= lang('App.login_email_label') ?></label>
                            <input type="email" class="form-control" name="email" placeholder="seu@email.com" value="<?= old('email') ?>" required autofocus>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label small fw-bold text-muted text-uppercase mb-0"><?= lang('App.login_password_label') ?></label>
                                <a href="<?= url_to('magic-link') ?>" class="small text-primary text-decoration-none fw-600"><?= lang('App.login_forgot_password') ?></a>
                            </div>
                            <input type="password" class="form-control" name="password" placeholder="••••••••" required>
                        </div>

                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" name="remember" id="remember">
                            <label class="form-check-label text-muted small" for="remember">
                                <?= lang('App.login_remember_me') ?>
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 mb-4">
                            <?= lang('App.login_btn_enter') ?> <i class="fa-solid fa-arrow-right-to-bracket ms-2"></i>
                        </button>

                        <div class="text-center">
                            <p class="text-muted small mb-3"><?= lang('App.login_no_account') ?></p>
                            <a href="<?= site_url('anuncie') ?>" class="btn-outline">
                                <i class="fas fa-plus"></i> <?= lang('App.login_btn_register') ?>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
