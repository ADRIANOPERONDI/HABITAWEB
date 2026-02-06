<?= $this->extend('install/layout') ?>

<?= $this->section('content') ?>

<h3 class="mb-4"><i class="fas fa-rocket text-primary"></i> Pronto para Instalar!</h3>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i> 
    Revise as configurações abaixo. Ao clicar em "Instalar", o sistema irá:
    <ul class="mt-2 mb-0">
        <li>Criar o arquivo .env</li>
        <li>Executar as migrations do banco de dados</li>
        <li>Criar a conta administradora</li>
        <li>Configurar os planos padrão</li>
    </ul>
</div>

<div class="card mb-3">
    <div class="card-header bg-light">
        <strong><i class="fas fa-database"></i> Banco de Dados</strong>
    </div>
    <div class="card-body">
        <p class="mb-1"><strong>Tipo:</strong> <?= $formData['db_driver'] ?? 'Postgre' ?></p>
        <p class="mb-1"><strong>Host:</strong> <?= $formData['db_host'] ?? '' ?></p>
        <p class="mb-1"><strong>Porta:</strong> <?= $formData['db_port'] ?? '' ?></p>
        <p class="mb-0"><strong>Banco:</strong> <?= $formData['db_name'] ?? '' ?></p>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-light">
        <strong><i class="fas fa-cog"></i> Site</strong>
    </div>
    <div class="card-body">
        <p class="mb-1"><strong>Nome:</strong> <?= $formData['site_name'] ?? '' ?></p>
        <p class="mb-1"><strong>Email:</strong> <?= $formData['site_email'] ?? '' ?></p>
        <p class="mb-0"><strong>URL:</strong> <?= $formData['base_url'] ?? '' ?></p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-light">
        <strong><i class="fas fa-user-shield"></i> Administrador</strong>
    </div>
    <div class="card-body">
        <p class="mb-0"><strong>Email:</strong> <?= $formData['admin_email'] ?? '' ?></p>
    </div>
</div>

<form action="<?= base_url('install/process') ?>" method="POST" id="installForm">
    <div class="d-flex gap-2">
        <a href="<?= base_url('install/step/4') ?>" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
        <button type="submit" class="btn btn-success ms-auto" id="installBtn">
            <i class="fas fa-rocket"></i> Instalar Agora!
        </button>
    </div>
</form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(document).ready(function() {
    $('#installForm').on('submit', function(e) {
        e.preventDefault();
        
        Swal.fire({
            title: 'Instalando...',
            html: 'Por favor aguarde. Isso pode levar alguns minutos.',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        // Envia o formulário
        this.submit();
    });
});
</script>
<?= $this->endSection() ?>
