<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $this->renderSection('title') ?> - <?= esc(app_setting('site.name', 'Habitaweb')) ?></title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5.2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        .form-control:focus {
            box-shadow: none;
            border-color: #0d6efd;
        }
    </style>
    <?= $this->renderSection('pageStyles') ?>
    <?php if ($color = app_setting('style.primary_color')): ?>
    <style>
        :root { --bs-primary: <?= $color ?>; --bs-link-color: <?= $color ?>; }
        .text-primary { color: <?= $color ?> !important; }
        .btn-primary { background-color: <?= $color ?> !important; border-color: <?= $color ?> !important; }
        .form-control:focus { border-color: <?= $color ?> !important; }
    </style>
    <?php endif; ?>
</head>
<body>
    <?= $this->renderSection('main') ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <?= $this->renderSection('pageScripts') ?>
</body>
</html>
