<?= $this->extend('Layouts/public') ?>

<?= $this->section('content') ?>

<div class="container py-5">
    <div class="row justify-content-center align-items-center">
        <!-- Lado Esquerdo: Benefícios -->
        <div class="col-lg-5 d-none d-lg-block">
            <h1 class="fw-bold mb-4 display-5">Anuncie para milhares de pessoas hoje mesmo.</h1>
            <p class="lead text-muted mb-5">Junte-se ao maior portal imobiliário da região. Ferramentas profissionais para você vender e alugar mais rápido.</p>
            
            <div class="d-flex align-items-start mb-4">
                <div class="icon-square bg-primary-soft text-primary rounded-circle p-3 me-3">
                    <i class="fas fa-bullhorn fa-lg"></i>
                </div>
                <div>
                    <h5 class="fw-bold">Visibilidade Máxima</h5>
                    <p class="text-muted">Seus imóveis no topo das buscas, com SEO otimizado e integração com redes sociais.</p>
                </div>
            </div>
            
            <div class="d-flex align-items-start mb-4">
                <div class="icon-square bg-secondary-soft text-secondary rounded-circle p-3 me-3">
                    <i class="fas fa-chart-line fa-lg"></i>
                </div>
                <div>
                    <h5 class="fw-bold">Gestão de Leads</h5>
                    <p class="text-muted">Painel completo para gerenciar contatos, visitas e propostas em tempo real.</p>
                </div>
            </div>
            
            <div class="d-flex align-items-start">
                <div class="icon-square bg-success-soft text-success rounded-circle p-3 me-3">
                    <i class="fas fa-check-circle fa-lg"></i>
                </div>
                <div>
                    <h5 class="fw-bold">Sem Burocracia</h5>
                    <p class="text-muted">Crie sua conta, escolha um plano e comece a anunciar em menos de 2 minutos.</p>
                </div>
            </div>
        </div>
        
        <!-- Lado Direito: Formulário -->
        <div class="col-lg-6 offset-lg-1">
            <div class="card border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                    <h4 class="fw-bold m-0">Criar Nova Conta</h4>
                    <p class="text-muted small">Preencha seus dados para começar</p>
                </div>
                <div class="card-body p-4">
                    
                    <?php if (session()->has('error')): ?>
                        <div class="alert alert-danger rounded-3">
                            <i class="fas fa-exclamation-circle me-2"></i> <?= session('error') ?>
                        </div>
                    <?php endif; ?>

                     <?php if (session()->has('errors')): ?>
                        <div class="alert alert-danger rounded-3">
                            <ul class="mb-0 ps-3">
                            <?php foreach (session('errors') as $error): ?>
                                <li><?= esc($error) ?></li>
                            <?php endforeach ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form action="<?= site_url('anuncie') ?>" method="post">
                        <?= csrf_field() ?>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Nome da Imobiliária ou Profissional</label>
                            <input type="text" name="nome" class="form-control form-control-lg bg-light border-0" placeholder="Ex: Imobiliária Silva ou João Silva" value="<?= old('nome') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">E-mail Profissional</label>
                            <input type="email" name="email" class="form-control form-control-lg bg-light border-0" placeholder="seu@email.com" value="<?= old('email') ?>" required>
                            <small class="text-muted">Não use emails temporários (temp-mail, etc)</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Tipo de Conta</label>
                            <select name="tipo_conta" class="form-select form-select-lg bg-light border-0" required>
                                <option value="" disabled selected>Selecione...</option>
                                <option value="IMOBILIARIA" <?= old('tipo_conta') == 'IMOBILIARIA' ? 'selected' : '' ?>>Imobiliária (PJ)</option>
                                <option value="CORRETOR" <?= old('tipo_conta') == 'CORRETOR' ? 'selected' : '' ?>>Corretor (CRECI)</option>
                                <option value="PF" <?= old('tipo_conta') == 'PF' ? 'selected' : '' ?>>Proprietário (PF)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Tipo de Documento</label>
                            <div class="d-grid gap-2" style="grid-template-columns: 1fr 1fr;">
                                <input type="radio" class="btn-check" name="tipo_documento" id="doc_cpf" value="CPF" <?= old('tipo_documento', 'CPF') == 'CPF' ? 'checked' : '' ?>>
                                <label class="doc-type-btn" for="doc_cpf">
                                    <i class="fas fa-user mb-2"></i>
                                    <div class="fw-bold">CPF</div>
                                    <small class="text-muted">Pessoa Física</small>
                                </label>
                                
                                <input type="radio" class="btn-check" name="tipo_documento" id="doc_cnpj" value="CNPJ" <?= old('tipo_documento') == 'CNPJ' ? 'checked' : '' ?>>
                                <label class="doc-type-btn" for="doc_cnpj">
                                    <i class="fas fa-building mb-2"></i>
                                    <div class="fw-bold">CNPJ</div>
                                    <small class="text-muted">Pessoa Jurídica</small>
                                </label>
                            </div>
                        </div>
                        
                        <style>
                            .doc-type-btn {
                                padding: 1.25rem 1rem;
                                border: 2px solid #e9ecef;
                                border-radius: 0.75rem;
                                background: #fff;
                                cursor: pointer;
                                transition: all 0.3s ease;
                                text-align: center;
                                display: flex;
                                flex-direction: column;
                                align-items: center;
                                justify-content: center;
                            }
                            
                            .doc-type-btn:hover {
                                border-color: var(--bs-primary);
                                background-color: rgba(var(--bs-primary-rgb), 0.05);
                                transform: translateY(-2px);
                                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                            }
                            
                            .btn-check:checked + .doc-type-btn {
                                border-color: var(--bs-primary);
                                background-color: var(--bs-primary);
                                color: white;
                                box-shadow: 0 4px 16px rgba(var(--bs-primary-rgb), 0.3);
                            }
                            
                            .btn-check:checked + .doc-type-btn i,
                            .btn-check:checked + .doc-type-btn small {
                                color: white !important;
                            }
                            
                            .doc-type-btn i {
                                font-size: 1.5rem;
                                color: var(--bs-primary);
                            }
                        </style>

                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted" id="label_documento">CPF</label>
                            <input type="text" name="documento" id="input_documento" class="form-control form-control-lg bg-light border-0" placeholder="000.000.000-00" value="<?= old('documento') ?>" required>
                            <div class="invalid-feedback" id="doc_error">Documento inválido</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-bold text-muted">Senha de Acesso</label>
                            <input type="password" name="password" id="password" class="form-control form-control-lg bg-light border-0" placeholder="Mínimo 8 caracteres" required>
                            <div class="progress mt-2" style="height: 5px;">
                                <div id="password_strength" class="progress-bar" style="width: 0%"></div>
                            </div>
                            <small id="password_help" class="text-muted">Use letras maiúsculas, minúsculas, números e caracteres especiais</small>
                        </div>

                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" value="" id="terms" required>
                            <label class="form-check-label text-muted small" for="terms">
                                Eu concordo com os <a href="#" class="text-primary fw-bold text-decoration-none">Termos de Uso</a> e <a href="#" class="text-primary fw-bold text-decoration-none">Política de Privacidade</a>.
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold fs-5 shadow-sm">
                            Criar Conta Grátis <i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </form>
                </div>
                <div class="card-footer bg-light border-0 py-3 text-center">
                    <span class="text-muted small">Já tem uma conta?</span>
                    <a href="<?= site_url('login') ?>" class="text-primary fw-bold text-decoration-none small ms-1">Fazer Login</a>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
// Aguardar o carregamento completo da página
window.addEventListener('load', function() {
    // Verificar se jQuery está disponível
    if (typeof jQuery === 'undefined') {
        console.error('jQuery não carregou!');
        return;
    }
    
    // Carregar jQuery Mask dinamicamente
    $.getScript('https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js', function() {
        // Máscaras dinâmicas CPF/CNPJ
        const cpfMask = '000.000.000-00';
        const cnpjMask = '00.000.000/0000-00';
        
        // Aplicar máscara inicial
        $('#input_documento').mask(cpfMask);
        
        // Alternar máscara ao mudar tipo
        $('input[name="tipo_documento"]').on('change', function() {
            if ($(this).val() === 'CPF') {
                $('#input_documento')
                    .mask(cpfMask)
                    .attr('placeholder', '000.000.000-00')
                    .val('');
                $('#label_documento').text('CPF');
            } else {
                $('#input_documento')
                    .mask(cnpjMask)
                    .attr('placeholder', '00.000.000/0000-00')
                    .val('');
                $('#label_documento').text('CNPJ');
            }
            $('#input_documento').removeClass('is-invalid is-valid');
        });
        
        // Validação CPF/CNPJ ao sair do campo
        $('#input_documento').on('blur', function() {
            const tipo = $('input[name="tipo_documento"]:checked').val();
            const valor = $(this).val().replace(/[^0-9]/g, '');
            
            let isValid = false;
            if (tipo === 'CPF' && valor.length === 11) {
                isValid = validarCPF(valor);
            } else if (tipo === 'CNPJ' && valor.length === 14) {
                isValid = validarCNPJ(valor);
            }
            
            if (!isValid && valor.length > 0) {
                $(this).addClass('is-invalid').removeClass('is-valid');
                $('#doc_error').text(tipo + ' inválido');
            } else if (isValid) {
                $(this).removeClass('is-invalid').addClass('is-valid');
            }
        });
    });
    
    // Indicador de força da senha (não depende do Mask)
    $('#password').on('keyup', function() {
        const pass = $(this).val();
        let strength = 0;
        
        if (pass.length >= 8) strength += 25;
        if (pass.match(/[a-z]/)) strength += 25;
        if (pass.match(/[A-Z]/)) strength += 25;
        if (pass.match(/[0-9]/) && pass.match(/[^a-zA-Z0-9]/)) strength += 25; // Combined numbers and special chars
        
        const colors = ['bg-danger', 'bg-warning', 'bg-info', 'bg-success'];
        const color = strength < 50 ? colors[0] : (strength < 75 ? colors[1] : (strength < 100 ? colors[2] : colors[3]));
        
        const bar = $('#password_strength');
        bar.css('width', strength + '%')
           .removeClass('bg-danger bg-warning bg-info bg-success')
           .addClass(color);

        const helpText = $('#password_help');
        helpText.removeClass('text-danger text-warning text-success text-muted');

        if (strength < 50) {
            helpText.text('Senha fraca - adicione mais caracteres variados').addClass('text-danger small');
        } else if (strength < 75) {
            helpText.text('Senha média - quase lá!').addClass('text-warning small');
        } else {
            $('#password_help').text('Senha forte! ✓').removeClass().addClass('text-success small');
        }
    });

    // Validação de Email AJAX
    $('input[name="email"]').on('blur', function() {
        const email = $(this).val();
        const input = $(this);
        
        // Remove estados anteriores
        input.removeClass('is-invalid is-valid');
        $('#email_feedback').remove();
        
        if (email.length > 5 && email.includes('@')) {
            // Loading state
            input.addClass('bg-light'); // Visual feedback
            
            $.get('<?= site_url("register/check-email") ?>', { email: email })
                .done(function(response) {
                    if (response.exists) {
                        input.addClass('is-invalid');
                        input.after('<div id="email_feedback" class="invalid-feedback">' + response.message + '</div>');
                    } else if (response.valid) {
                         input.addClass('is-valid');
                    }
                })
                .fail(function() {
                    console.error('Erro ao validar email');
                });
        }
    });

});

// Validação de CPF
function validarCPF(cpf) {
    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
    
    let sum = 0;
    for (let i = 0; i < 9; i++) sum += parseInt(cpf[i]) * (10 - i);
    let remainder = sum % 11;
    let digit1 = (remainder < 2) ? 0 : 11 - remainder;
    if (parseInt(cpf[9]) !== digit1) return false;
    
    sum = 0;
    for (let i = 0; i < 10; i++) sum += parseInt(cpf[i]) * (11 - i);
    remainder = sum % 11;
    let digit2 = (remainder < 2) ? 0 : 11 - remainder;
    
    return parseInt(cpf[10]) === digit2;
}

// Validação de CNPJ
function validarCNPJ(cnpj) {
    if (cnpj.length !== 14 || /^(\d)\1{13}$/.test(cnpj)) return false;
    
    let weights = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    let sum = 0;
    for (let i = 0; i < 12; i++) sum += parseInt(cnpj[i]) * weights[i];
    let remainder = sum % 11;
    let digit1 = (remainder < 2) ? 0 : 11 - remainder;
    if (parseInt(cnpj[12]) !== digit1) return false;
    
    weights = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    sum = 0;
    for (let i = 0; i < 13; i++) sum += parseInt(cnpj[i]) * weights[i];
    remainder = sum % 11;
    let digit2 = (remainder < 2) ? 0 : 11 - remainder;
    
    return parseInt(cnpj[13]) === digit2;
}
</script>

<?= $this->endSection() ?>
