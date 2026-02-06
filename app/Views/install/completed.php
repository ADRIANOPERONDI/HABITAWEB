<?= $this->extend('install/layout') ?>

<?= $this->section('content') ?>

<div class="install-body text-center py-5">
    <div class="mb-4">
        <i class="fas fa-check-circle text-success" style="font-size: 5rem;"></i>
    </div>
    
    <h2 class="mb-3">Instalação Concluída com Sucesso!</h2>
    
    <p class="text-muted mb-4">
        O Habitaweb foi instalado e configurado corretamente.<br>
        Você já pode fazer login com sua conta de administrador.
    </p>
    
    <div class="card mb-4 mx-auto" style="max-width: 400px;">
        <div card-body p-3">
            <p class="mb-1"><strong>Email de acesso:</strong></p>
            <p class="mb-0"><?= esc($admin_email) ?></p>
        </div>
    </div>
    
    <a href="<?= base_url('admin/login') ?>" class="btn btn-primary btn-lg">
        <i class="fas fa-sign-in-alt"></i> Fazer Login
    </a>
    
    <div class="alert alert-warning mt-4 mx-auto" style="max-width: 600px;">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Importante:</strong> Configure as chaves da API Asaas no arquivo .env para ativar pagamentos.
    </div>
</div>

<?= $this->endSection() ?>
