<?= $this->extend('install/layout') ?>

<?= $this->section('head') ?>
<meta http-equiv="refresh" content="2;url=<?= base_url('install/finalize') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="text-center py-5">
    <div class="mb-4">
        <div class="spinner-border text-primary" style="width: 4rem; height: 4rem;" role="status">
            <span class="visually-hidden">Preparando...</span>
        </div>
    </div>
    
    <h3 class="mb-3">Preparando Instalação...</h3>
    
    <p class="text-muted">
        Aguarde enquanto configuramos o banco de dados e as tabelas finais...
    </p>
    
    <div class="alert alert-info mt-4">
        <i class="fas fa-info-circle"></i> Você será redirecionado automaticamente em instantes. 
        <br>
        <span class="small font-italic">Se não carregar em 5 segundos, <a href="<?= base_url('install/finalize') ?>" class="alert-link">clique aqui</a>.</span>
    </div>
</div>

<?= $this->endSection() ?>
