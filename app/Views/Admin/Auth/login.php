<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso Restrito - <?= esc(app_setting('site.name', 'Habitaweb Admin')) ?></title>
    
    <?php if ($favicon = app_setting('style.favicon_url')): ?>
        <link rel="icon" type="image/x-icon" href="<?= base_url($favicon) ?>">
    <?php endif; ?>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Outfit:wght@400;600;800&display=swap" rel="stylesheet">
    
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
            background: #f8fafc;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }
        .login-card {
            border: none;
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
            background: #fff;
        }
        .login-header {
            background: var(--primary-gradient);
            padding: 50px 30px;
            text-align: center;
            color: #fff;
            position: relative;
        }
        .login-header i {
            font-size: 3rem;
            margin-bottom: 15px;
            display: block;
        }
        .login-body {
            padding: 40px;
        }
        .form-label {
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            color: #1e293b;
        }
        .form-control {
            border-radius: 12px;
            padding: 14px 18px;
            background: #f1f5f9;
            border: 2px solid transparent;
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
            padding: 14px !important;
            border-radius: 12px !important;
            font-weight: 700 !important;
            font-family: 'Outfit', sans-serif;
            box-shadow: 0 10px 20px -5px rgba(99, 102, 241, 0.4) !important;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px -10px rgba(99, 102, 241, 0.6) !important;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="login-header">
            <?php if ($logo = app_setting('style.logo_url')): ?>
                <img src="<?= base_url($logo) ?>" alt="Logo" height="60" class="mb-3 object-fit-contain">
            <?php else: ?>
                <i class="fa-solid fa-house-chimney-user"></i>
            <?php endif; ?>
            <h3 class="fw-bold mb-1" style="font-family: 'Outfit', sans-serif;"><?= esc(app_setting('site.name', 'Habitaweb')) ?> Admin</h3>
            <p class="small mb-0 opacity-75">Acesso Restrito ao Sistema</p>
        </div>
        
        <div class="login-body">
            <?php if (session('error')) : ?>
                <div class="alert alert-danger shadow-sm mb-4">
                    <i class="fa-solid fa-circle-exclamation me-2"></i> <?= session('error') ?>
                </div>
            <?php endif ?>

            <?php if (session('message')) : ?>
                <div class="alert alert-success shadow-sm mb-4">
                    <?= session('message') ?>
                </div>
            <?php endif ?>

            <form action="<?= base_url('admin/login') ?>" method="post">
                <?= csrf_field() ?>

                <!-- Email -->
                <div class="mb-3">
                    <label class="form-label fw-600 text-muted small">E-mail Corporativo</label>
                    <input type="email" class="form-control" name="email" inputmode="email" autocomplete="email" placeholder="nome@empresa.com" value="<?= old('email') ?>" required>
                </div>

                <!-- Password -->
                <div class="mb-4">
                    <label class="form-label fw-600 text-muted small">Senha</label>
                    <input type="password" class="form-control" name="password" inputmode="text" autocomplete="current-password" placeholder="••••••••" required>
                </div>

                <div class="d-grid shadow-sm">
                    <button type="submit" class="btn btn-primary">
                        Entrar no Painel <i class="fa-solid fa-arrow-right ms-2"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

</body>
</html>
