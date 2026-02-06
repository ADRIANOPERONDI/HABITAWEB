<?= $this->extend('install/layout') ?>

<?= $this->section('content') ?>

<h3 class="mb-4"><i class="fas fa-cog text-primary"></i> Configurações Gerais</h3>

<form action="<?= base_url('install/saveStep') ?>" method="POST">
    <input type="hidden" name="step" value="3">
    
    <div class="mb-3">
        <label for="site_name" class="form-label">Nome do Site</label>
        <input type="text" class="form-control" id="site_name" name="site_name" 
               value="<?= $formData['site_name'] ?? 'Habitaweb' ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="site_email" class="form-label">Email do Site</label>
        <input type="email" class="form-control" id="site_email" name="site_email" 
               value="<?= $formData['site_email'] ?? '' ?>" required>
        <small class="text-muted">Email usado para notificações do sistema</small>
    </div>
    
    <div class="mb-3">
        <label for="base_url" class="form-label">URL Base</label>
        <input type="url" class="form-control" id="base_url" name="base_url" 
               value="<?= $formData['base_url'] ?? base_url() ?>" required>
        <small class="text-muted">URL completa do seu site (ex: https://seusite.com.br)</small>
    </div>
    
    <div class="d-flex gap-2">
        <a href="<?= base_url('install/step/2') ?>" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
        <button type="submit" class="btn btn-primary ms-auto">
            Próximo <i class="fas fa-arrow-right ms-1"></i>
        </button>
    </div>
</form>

<?= $this->endSection() ?>
