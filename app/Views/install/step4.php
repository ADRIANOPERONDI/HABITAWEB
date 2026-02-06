<?= $this->extend('install/layout') ?>

<?= $this->section('content') ?>

<h3 class="mb-4"><i class="fas fa-user-shield text-primary"></i> Conta Administradora</h3>

<form action="<?= base_url('install/saveStep') ?>" method="POST">
    <input type="hidden" name="step" value="4">
    
    <div class="mb-3">
        <label for="admin_email" class="form-label">Email do Administrador</label>
        <input type="email" class="form-control" id="admin_email" name="admin_email" 
               value="<?= $formData['admin_email'] ?? '' ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="admin_password" class="form-label">Senha</label>
        <input type="password" class="form-control" id="admin_password" name="admin_password" 
               minlength="8" required>
        <small class="text-muted">Mínimo 8 caracteres</small>
    </div>
    
    <div class="mb-3">
        <label for="admin_password_confirm" class="form-label">Confirmar Senha</label>
        <input type="password" class="form-control" id="admin_password_confirm" 
               name="admin_password_confirm" minlength="8" required>
    </div>
    
    <div class="d-flex gap-2">
        <a href="<?= base_url('install/step/3') ?>" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
        <button type="submit" class="btn btn-primary ms-auto">
            Próximo <i class="fas fa-arrow-right ms-1"></i>
        </button>
    </div>
</form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(document).ready(function() {
    $('form').on('submit', function(e) {
        const password = $('#admin_password').val();
        const confirm = $('#admin_password_confirm').val();
        
        if (password !== confirm) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: 'As senhas não coincidem!',
                confirmButtonColor: '#2563eb'
            });
            return false;
        }
    });
});
</script>
<?= $this->endSection() ?>
