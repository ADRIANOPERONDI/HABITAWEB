<?= $this->extend('install/layout') ?>

<?= $this->section('content') ?>

<h3 class="mb-4"><i class="fas fa-database text-primary"></i> Configuração do Banco de Dados</h3>

<form action="<?= base_url('install/saveStep') ?>" method="POST" id="dbForm">
    <input type="hidden" name="step" value="2">
    
    <div class="mb-3">
        <label for="db_driver" class="form-label">Tipo de Banco de Dados</label>
        <select class="form-select" id="db_driver" name="db_driver" required>
            <option value="Postgre" <?= ($formData['db_driver'] ?? '') == 'Postgre' ? 'selected' : '' ?>>PostgreSQL</option>
            <option value="MySQLi" <?= ($formData['db_driver'] ?? '') == 'MySQLi' ? 'selected' : '' ?>>MySQL / MariaDB</option>
        </select>
    </div>
    
    <div class="mb-3">
        <label for="db_host" class="form-label">Host do Banco</label>
        <input type="text" class="form-control" id="db_host" name="db_host" 
               value="<?= $formData['db_host'] ?? 'localhost' ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="db_port" class="form-label">Porta</label>
        <input type="number" class="form-control" id="db_port" name="db_port" 
               value="<?= $formData['db_port'] ?? '5432' ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="db_name" class="form-label">Nome do Banco</label>
        <input type="text" class="form-control" id="db_name" name="db_name" 
               value="<?= $formData['db_name'] ?? 'habitaweb' ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="db_user" class="form-label">Usuário</label>
        <input type="text" class="form-control" id="db_user" name="db_user" 
               value="<?= $formData['db_user'] ?? 'postgres' ?>" required>
    </div>
    
    <div class="mb-3">
        <label for="db_pass" class="form-label">Senha</label>
        <input type="password" class="form-control" id="db_pass" name="db_pass" 
               value="<?= $formData['db_pass'] ?? '' ?>">
    </div>
    
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-primary" id="testConnectionBtn">
            <i class="fas fa-plug"></i> Testar Conexão
        </button>
        <button type="submit" class="btn btn-primary ms-auto" id="nextBtn" disabled>
            Próximo <i class="fas fa-arrow-right ms-1"></i>
        </button>
    </div>
</form>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
$(document).ready(function() {
    let connectionTested = false;
    
    // Quando mudar o tipo de banco, atualiza a porta
    $('#db_driver').on('change', function() {
        const port = $(this).val() === 'MySQLi' ? 3306 : 5432;
        $('#db_port').val(port);
        connectionTested = false;
        $('#nextBtn').prop('disabled', true);
    });
    
    $('#testConnectionBtn').on('click', function() {
        const btn = $(this);
        const originalHtml = btn.html();
        btn.html('<i class="fas fa-spinner fa-spin"></i> Testando...').prop('disabled', true);
        
        $.ajax({
            url: '<?= base_url('install/test-database') ?>',
            method: 'POST',
            data: {
                db_driver: $('#db_driver').val(),
                db_host: $('#db_host').val(),
                db_port: $('#db_port').val(),
                db_name: $('#db_name').val(),
                db_user: $('#db_user').val(),
                db_pass: $('#db_pass').val()
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Sucesso!',
                        text: response.message,
                        confirmButtonColor: '#2563eb'
                    });
                    connectionTested = true;
                    $('#nextBtn').prop('disabled', false);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Erro',
                        text: response.message,
                        confirmButtonColor: '#2563eb'
                    });
                }
            },
            error: function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro',
                    text: 'Erro ao testar conexão. Verifique os dados.',
                    confirmButtonColor: '#2563eb'
                });
            },
            complete: function() {
                btn.html(originalHtml).prop('disabled', false);
            }
        });
    });
    
    // Monitora mudanças nos campos
    $('#dbForm input, #dbForm select').on('change', function() {
        if (connectionTested) {
            connectionTested = false;
            $('#nextBtn').prop('disabled', true);
        }
    });
});
</script>
<?= $this->endSection() ?>
