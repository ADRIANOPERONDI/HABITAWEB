<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Configurações do Sistema<?= $this->endSection() ?>

<?= $this->section('page_title') ?>Configurações<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<style>
    .settings-card { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    .nav-settings { background: #f8f9fa; border-radius: 10px; padding: 5px; }
    .nav-settings .nav-link { border: none; border-radius: 8px; color: #6c757d; font-weight: 500; transition: all 0.3s; }
    .nav-settings .nav-link.active { background: #fff; color: var(--bs-primary) !important; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .nav-settings .nav-link i { color: inherit !important; transition: all 0.3s; }
    .setting-item { padding: 1.5rem 0; border-bottom: 1px solid #f1f1f1; }
    .setting-item:last-child { border-bottom: none; }
    .setting-label { font-weight: 600; color: #344767; margin-bottom: 0.25rem; display: block; }
    .setting-description { font-size: 0.875rem; color: #67748e; margin-bottom: 1rem; }
    .color-preview { width: 40px; height: 40px; border-radius: 8px; border: 2px solid #fff; box-shadow: 0 0 5px rgba(0,0,0,0.1); }
    .btn-save-fixed { position: fixed; bottom: 30px; right: 30px; z-index: 1000; border-radius: 50px; padding: 12px 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
    
    /* Mapeamento amigável de grupos */
    <?php
    $groupLabels = [
        'general'       => ['label' => 'Geral', 'icon' => 'fa-gear'],
        'seo'           => ['label' => 'SEO / Google', 'icon' => 'fa-search'],
        'appearance'    => ['label' => 'Identidade Visual', 'icon' => 'fa-palette'],
        'social'        => ['label' => 'Redes Sociais', 'icon' => 'fa-share-nodes'],
        'email'         => ['label' => 'E-mail / SMTP', 'icon' => 'fa-envelope'],
        'notifications' => ['label' => 'Notificações', 'icon' => 'fa-bell'],
    ];
    ?>
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="row">
    <div class="col-lg-11 mx-auto">
        <?php if ($isSuperAdmin): ?>
        <!-- Acesso Rápido a Gateways -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 rounded-4 shadow-sm bg-primary text-white overflow-hidden">
                    <div class="card-body p-4 d-md-flex align-items-center justify-content-between position-relative">
                        <div style="z-index: 2">
                            <h4 class="fw-bold mb-1"><i class="fa-solid fa-credit-card me-2"></i>Gerenciar Pagamentos</h4>
                            <p class="mb-md-0 opacity-75">Configure seus Gateways (Asaas, Stripe, etc.) e defina qual será o principal para o sistema.</p>
                        </div>
                        <div class="mt-3 mt-md-0" style="z-index: 2">
                            <a href="<?= site_url('admin/payment-gateways') ?>" class="btn btn-white rounded-pill px-4 fw-bold">
                                Ajustar Gateways
                            </a>
                        </div>
                        <!-- Elemento Decorativo -->
                        <i class="fa-solid fa-cloud-bolt position-absolute end-0 bottom-0 opacity-25" style="font-size: 8rem; margin-right: -1rem; margin-bottom: -2rem;"></i>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="mb-4">
            <nav class="nav nav-pills nav-settings shadow-sm" id="settingsTab" role="tablist">
                <?php $active = true; foreach ($grouped as $group => $items): ?>
                <?php $info = $groupLabels[$group] ?? ['label' => ucfirst($group), 'icon' => 'fa-list']; ?>
                <button class="nav-link <?= $active ? 'active' : '' ?> px-4 py-2" 
                        id="<?= $group ?>-tab" 
                        data-bs-toggle="tab" 
                        data-bs-target="#tab-<?= $group ?>" 
                        type="button" 
                        role="tab">
                    <i class="fa-solid <?= $info['icon'] ?> me-2"></i> <?= $info['label'] ?>
                </button>
                <?php $active = false; endforeach; ?>
            </nav>
        </div>

        <form id="settingsForm" action="<?= site_url('admin/settings') ?>" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            
            <div class="tab-content" id="settingsTabContent">
                <?php $active = true; foreach ($grouped as $group => $items): ?>
                <div class="tab-pane fade <?= $active ? 'show active' : '' ?>" id="tab-<?= $group ?>" role="tabpanel">
                    
                    <div class="card settings-card">
                        <div class="card-header bg-white border-bottom p-4 d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="fw-bold mb-0"><?= $groupLabels[$group]['label'] ?? ucfirst($group) ?></h5>
                                <p class="text-muted small mb-0">Gerencie as definições do grupo <?= strtolower($groupLabels[$group]['label'] ?? $group) ?>.</p>
                            </div>
                            <?php if ($group === 'email' && $isSuperAdmin): ?>
                                <button type="button" class="btn btn-outline-primary rounded-pill btn-sm px-3" onclick="testSmtp()">
                                    <i class="fa-solid fa-paper-plane me-1"></i> Testar SMTP
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-4">
                            <?php foreach ($items as $setting): ?>
                            <div class="setting-item">
                                <div class="row align-items-center">
                                    <div class="col-md-5">
                                        <span class="setting-label"><?= esc($setting->label ?? $setting->key) ?></span>
                                        <p class="setting-description mb-md-0"><?= esc($setting->description) ?></p>
                                    </div>
                                    <div class="col-md-7">
                                        <?php if ($setting->type == 'color'): ?>
                                            <div class="d-flex align-items-center">
                                                <input type="color" class="form-control form-control-color me-3" 
                                                       name="<?= $setting->key ?>" 
                                                       value="<?= esc($setting->value) ?>" 
                                                       title="Escolha a cor">
                                                <input type="text" class="form-control font-monospace w-25 hex-input" 
                                                       value="<?= esc($setting->value) ?>">
                                            </div>
                                        <?php elseif ($setting->type == 'text'): ?>
                                            <textarea class="form-control" name="<?= $setting->key ?>" rows="3" placeholder="Digite aqui..."><?= esc($setting->value) ?></textarea>
                                        <?php elseif ($setting->type == 'image'): ?>
                                            <div class="mb-3">
                                                <input type="file" class="form-control input-premium" name="<?= $setting->key ?>" accept="image/*" onchange="previewSettingImage(this, 'preview-<?= str_replace('.', '-', $setting->key) ?>')">
                                                <div class="form-text small">Escolha um arquivo para substituir o atual.</div>
                                            </div>
                                            <?php if ($setting->value): ?>
                                                <div class="mt-2 p-3 border rounded-4 d-inline-block bg-white shadow-sm position-relative">
                                                    <img src="<?= base_url($setting->value) ?>" id="preview-<?= str_replace('.', '-', $setting->key) ?>" height="60" class="object-fit-contain" alt="Preview">
                                                    <div class="small fw-bold text-muted mt-1 text-center">Atual</div>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif ($setting->type == 'boolean'): ?>
                                            <div class="form-check form-switch form-switch-lg">
                                                <input type="hidden" name="<?= $setting->key ?>" value="0">
                                                <input class="form-check-input" type="checkbox" name="<?= $setting->key ?>" value="1" <?= $setting->value == '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label text-muted small">Ativado / Desativado</label>
                                            </div>
                                        <?php else: ?>
                                            <input type="<?= ($group === 'email' && str_contains($setting->key, 'pass')) ? 'password' : 'text' ?>" 
                                                   class="form-control" name="<?= $setting->key ?>" value="<?= esc($setting->value) ?>" placeholder="Insira o valor...">
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div>
                <?php $active = false; endforeach; ?>
            </div>


            <button type="submit" class="btn btn-primary btn-save-fixed" id="btnSaveSettings">
                <i class="fa-solid fa-cloud-arrow-up me-2"></i> Salvar Todas as Alterações
            </button>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Sincroniza Color Picker com Input de Texto
    $('.form-control-color').on('input', function() {
        $(this).next('.hex-input').val($(this).val());
    });

    $('.hex-input').on('input', function() {
        let val = $(this).val();
        if(/^#[0-9A-F]{6}$/i.test(val)) {
            $(this).prev('.form-control-color').val(val);
        }
    });

    // Submissão via AJAX
    $('#settingsForm').on('submit', function(e) {
        e.preventDefault();
        
        let form = $(this);
        let btn = $('#btnSaveSettings');
        let originalHtml = btn.html();
        
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-2"></i> Salvando...');
        
        // Usar FormData para suportar arquivos
        let formData = new FormData(this);
        
        $.ajax({
            url: form.attr('action'),
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if(response.success) {
                    Toast.fire({
                        icon: 'success',
                        title: response.message || 'Configurações gravadas!'
                    });
                    
                    // Opcional: Recarregar suave se mudar cores/logos críticos
                    // window.location.reload(); 
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Erro ao salvar',
                        text: response.message || 'Ocorreu um erro inesperado.'
                    });
                }
            },
            error: function(xhr) {
                Swal.fire({
                    icon: 'error',
                    title: 'Erro de Conexão',
                    text: 'Não foi possível salvar as configurações. Tente novamente.'
                });
                console.error(xhr);
            },
            complete: function() {
                btn.prop('disabled', false).html(originalHtml);
            }
        });
    });
});

function previewSettingImage(input, previewId) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#' + previewId).attr('src', e.target.result);
        }
        reader.readAsDataURL(input.files[0]);
    }
}
function testSmtp() {
    Swal.fire({
        title: 'Testar Conexão SMTP',
        text: 'Insira o e-mail que receberá a mensagem de teste:',
        input: 'email',
        inputPlaceholder: 'seu-email@exemplo.com',
        showCancelButton: true,
        confirmButtonText: 'Enviar Teste',
        cancelButtonText: 'Cancelar',
        showLoaderOnConfirm: true,
        preConfirm: (email) => {
            return $.post('<?= site_url('admin/settings/test-email') ?>', { email: email })
                .then(response => {
                    if (!response.success) {
                        throw new Error(response.message || 'Falha no envio.');
                    }
                    return response;
                })
                .catch(error => {
                    Swal.showValidationMessage(`Erro: ${error.message}`);
                });
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                icon: 'success',
                title: 'Sucesso!',
                text: result.value.message
            });
        }
    });
}
</script>


<?= $this->endSection() ?>
