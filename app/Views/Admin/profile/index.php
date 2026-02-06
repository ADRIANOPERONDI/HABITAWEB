<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Meu Perfil<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Configurações da Conta<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .profile-card { border: none; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
    .logo-preview-wrapper { width: 120px; height: 120px; border-radius: 20px; overflow: visible; background: #fff; border: 2px dashed #e2e8f0; display: flex; align-items: center; justify-content: center; margin-bottom: 1rem; position: relative; transition: all 0.3s ease; }
    .logo-preview-wrapper:hover { border-color: var(--primary-color); background: rgba(var(--primary-rgb), 0.02); }
    .logo-preview-wrapper img { max-width: 100%; max-height: 100%; object-fit: cover; border-radius: 18px; }
    .upload-btn-chip { position: absolute; bottom: -5px; right: -5px; background: var(--primary-color); color: #fff; width: 36px; height: 36px; border-radius: 12px; display: flex; align-items: center; justify-content: center; cursor: pointer; border: 3px solid #fff; box-shadow: 0 4px 12px rgba(var(--primary-rgb), 0.3); transition: all 0.2s ease; z-index: 5; }
    .upload-btn-chip:hover { transform: scale(1.1); background: var(--secondary-color); }
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row">
    <div class="col-lg-8">
        <div class="card profile-card animate-fade-in">
            <div class="card-body p-4 p-md-5">
                <form action="<?= site_url('admin/profile') ?>" method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    
                    <div class="d-flex align-items-center gap-4 mb-5 pb-4 border-bottom">
                        <div class="logo-preview-wrapper">
                            <?php if ($account->logo): ?>
                                <img src="<?= base_url($account->logo) ?>" alt="Logo da Conta" id="logoPreview">
                            <?php else: ?>
                                <i class="fa-solid fa-building fa-2x text-light" id="logoPlaceholder"></i>
                                <img src="" alt="Preview" id="logoPreview" style="display: none;">
                            <?php endif; ?>
                            <label for="logoInput" class="upload-btn-chip">
                                <i class="fa-solid fa-camera"></i>
                            </label>
                            <input type="file" name="logo" id="logoInput" class="d-none" accept="image/*" onchange="previewImage(this)">
                        </div>
                        <div>
                            <h4 class="fw-bold mb-1"><?= esc($account->nome) ?></h4>
                            <p class="text-muted mb-0 small text-uppercase fw-bold letter-spacing-1"><?= esc($account->tipo_conta) ?></p>
                            <span class="badge bg-success-soft text-success rounded-pill px-3 py-2 mt-2">Conta Ativa</span>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Nome do Corretor (Exibido nos Imóveis)</label>
                            <input type="text" name="user_nome" class="form-control input-premium" value="<?= esc($user->nome ?? $user->username) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Nome da Imobiliária / Conta</label>
                            <input type="text" name="nome" class="form-control input-premium" value="<?= esc($account->nome) ?>" required>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label fw-bold small text-muted">CPF / CNPJ (Obrigatório para Pagamentos)</label>
                            <input type="text" name="documento" class="form-control input-premium" value="<?= esc($account->documento) ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">E-mail de Contato</label>
                            <input type="email" name="email" class="form-control input-premium" value="<?= esc($account->email) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">CRECI (Se houver)</label>
                            <input type="text" name="creci" class="form-control input-premium" value="<?= esc($account->creci) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">Telefone</label>
                            <input type="text" name="telefone" class="form-control input-premium" value="<?= esc($account->telefone) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">WhatsApp</label>
                            <input type="text" name="whatsapp" class="form-control input-premium" value="<?= esc($account->whatsapp) ?>">
                        </div>
                    </div>

                    <div class="mt-5 pt-4 border-top">
                        <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold">
                            <i class="fa-solid fa-save me-2"></i> Salvar Alterações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card profile-card border-0 bg-dark text-white p-4 h-100">
            <h5 class="fw-bold mb-4">Dicas de Branding</h5>
            <div class="d-flex flex-column gap-3">
                <div class="d-flex gap-3">
                    <div class="text-primary fs-4"><i class="fa-solid fa-circle-check"></i></div>
                    <div>
                        <h6 class="mb-1 small fw-bold">Logo Transparente</h6>
                        <p class="mb-0 xsmall opacity-75">Use arquivos PNG com fundo transparente para um visual mais profissional.</p>
                    </div>
                </div>
                <div class="d-flex gap-3">
                    <div class="text-primary fs-4"><i class="fa-solid fa-circle-check"></i></div>
                    <div>
                        <h6 class="mb-1 small fw-bold">Tamanho Ideal</h6>
                        <p class="mb-0 xsmall opacity-75">Recomendamos 400x400px para que sua marca fique nítida em todos os dispositivos.</p>
                    </div>
                </div>
            </div>
            
            <div class="mt-auto p-3 rounded-4" style="background: rgba(255,255,255,0.1)">
                <p class="mb-0 xsmall fw-bold"><i class="fa-solid fa-info-circle me-1"></i> Sua logo será exibida em:</p>
                <ul class="xsmall opacity-75 mt-2 mb-0 ps-3">
                    <li>Página de detalhes dos seus imóveis</li>
                    <li>Perfil de busca do portal</li>
                    <li>Cards de listagem</li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#logoPreview').attr('src', e.target.result).show();
            $('#logoPlaceholder').hide();
        }
        reader.readAsDataURL(input.files[0]);
    }
}

$(document).ready(function() {
    // Máscara dinâmica para CPF/CNPJ
    const docMaskBehavior = function (val) {
        return val.replace(/\D/g, '').length <= 11 ? '000.000.000-009' : '00.000.000/0000-00';
    };

    const docOptions = {
        onKeyPress: function(val, e, field, options) {
            field.mask(docMaskBehavior.apply({}, arguments), options);
        }
    };

    $('input[name="documento"]').mask(docMaskBehavior, docOptions);

    // Máscara para Telefone (com 9º dígito opcional)
    const phoneMaskBehavior = function (val) {
        return val.replace(/\D/g, '').length === 11 ? '(00) 00000-0000' : '(00) 0000-00009';
    };

    const phoneOptions = {
        onKeyPress: function(val, e, field, options) {
            field.mask(phoneMaskBehavior.apply({}, arguments), options);
        }
    };

    $('input[name="telefone"], input[name="whatsapp"]').mask(phoneMaskBehavior, phoneOptions);
});
</script>
<?= $this->endSection() ?>
