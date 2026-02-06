<?= $this->extend('install/layout') ?>

<?= $this->section('content') ?>

<h3 class="mb-4"><i class="fas fa-check-circle text-primary"></i> Verificação de Requisitos</h3>

<div class="requirements-container">
    <?php
    $phpVersion = version_compare(PHP_VERSION, '8.1.0', '>=');
    $intlExt = extension_loaded('intl');
    $mbstringExt = extension_loaded('mbstring');
    $pdoExt = extension_loaded('pdo');
    $curlExt = extension_loaded('curl');
    $gdExt = extension_loaded('gd');
    $writableDir = is_writable(WRITEPATH);
    
    $allRequirementsMet = $phpVersion && $intlExt && $mbstringExt && $pdoExt && $curlExt && $gdExt && $writableDir;
    ?>
    
    <div class="requirement <?= $phpVersion ? 'success' : 'error' ?>">
        <i class="fas <?= $phpVersion ? 'fa-check-circle' : 'fa-times-circle' ?> me-2"></i>
        <span>PHP >= 8.1 (Atual: <?= PHP_VERSION ?>)</span>
    </div>
    
    <div class="requirement <?= $intlExt ? 'success' : 'error' ?>">
        <i class="fas <?= $intlExt ? 'fa-check-circle' : 'fa-times-circle' ?> me-2"></i>
        <span>Extensão intl</span>
    </div>
    
    <div class="requirement <?= $mbstringExt ? 'success' : 'error' ?>">
        <i class="fas <?= $mbstringExt ? 'fa-check-circle' : 'fa-times-circle' ?> me-2"></i>
        <span>Extensão mbstring</span>
    </div>
    
    <div class="requirement <?= $pdoExt ? 'success' : 'error' ?>">
        <i class="fas <?= $pdoExt ? 'fa-check-circle' : 'fa-times-circle' ?> me-2"></i>
        <span>Extensão PDO</span>
    </div>
    
    <div class="requirement <?= $curlExt ? 'success' : 'error' ?>">
        <i class="fas <?= $curlExt ? 'fa-check-circle' : 'fa-times-circle' ?> me-2"></i>
        <span>Extensão cURL</span>
    </div>
    
    <div class="requirement <?= $gdExt ? 'success' : 'error' ?>">
        <i class="fas <?= $gdExt ? 'fa-check-circle' : 'fa-times-circle' ?> me-2"></i>
        <span>Extensão GD</span>
    </div>
    
    <div class="requirement <?= $writableDir ? 'success' : 'error' ?>">
        <i class="fas <?= $writableDir ? 'fa-check-circle' : 'fa-times-circle' ?> me-2"></i>
        <span>Permissões de escrita (writable/)</span>
    </div>
</div>

<div class="mt-4">
    <a href="<?= base_url('install/step/2') ?>" 
       class="btn btn-primary <?= !$allRequirementsMet ? 'disabled' : '' ?>">
        Próximo <i class="fas fa-arrow-right ms-1"></i>
    </a>
</div>

<?php if (!$allRequirementsMet): ?>
<div class="alert alert-warning mt-3">
    <i class="fas fa-exclamation-triangle"></i> 
    Por favor, resolva os requisitos pendentes antes de continuar.
</div>
<?php endif; ?>

<?= $this->endSection() ?>
