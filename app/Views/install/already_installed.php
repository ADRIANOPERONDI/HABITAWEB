<?= $this->extend('install/layout') ?>

<?= $this->section('content') ?>

<div class="install-body text-center py-5">
    <div class="mb-4">
        <i class="fas fa-info-circle text-primary" style="font-size: 5rem;"></i>
    </div>
    
    <h2 class="mb-3">Sistema Já Instalado</h2>
    
    <p class="text-muted mb-4">
        O Habitaweb já foi instalado anteriormente.<br>
        Para reinstalar, remova o arquivo <code>writable/.installed</code>
    </p>
    
    <a href="<?= base_url('/') ?>" class="btn btn-primary">
        <i class="fas fa-home"></i> Ir para a Home
    </a>
</div>

<?= $this->endSection() ?>
