<?= $this->extend('Layouts/public') ?>

<?= $this->section('content') ?>

<div class="container py-5">
    <div class="row justify-content-center">
        <!-- Detalhes do Pedido -->
        <div class="col-md-4 mb-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-4">Resumo do Pedido</h5>
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted">Plano</span>
                        <span class="fw-bold text-primary"><?= esc($plan->nome) ?></span>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-muted">Ciclo</span>
                        <span class="badge bg-light text-dark">Mensal</span>
                    </div>

                    <!-- Coupon Input -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Cupom de Desconto</label>
                        <div class="input-group">
                            <input type="text" class="form-control text-uppercase" id="coupon_code_input" placeholder="Código">
                            <button class="btn btn-outline-primary" type="button" id="apply_coupon_btn">Aplicar</button>
                        </div>
                        <div id="coupon_feedback" class="small mt-1 fw-bold"></div>
                    </div>
                    
                    <hr class="my-3">

                    <!-- Discount Row (Hidden initially) -->
                    <div class="d-flex justify-content-between align-items-center mb-2 d-none" id="discount_row">
                        <span class="text-success">Desconto</span>
                        <span class="text-success fw-bold" id="discount_display">- R$ 0,00</span>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-dark fs-5">Total</span>
                        <span class="fw-bold text-success fs-4" id="total_display">R$ <?= number_format($plan->preco_mensal, 2, ',', '.') ?></span>
                    </div>
                    
                    <div class="mt-4 small text-muted">
                        <i class="fas fa-lock me-1"></i> Ambiente seguro
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Formulário de Pagamento -->
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h4 class="fw-bold mb-4">Escolha a forma de pagamento</h4>
                    
                    <form action="<?= site_url('checkout/process') ?>" method="post" id="paymentForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="plan_id" value="<?= $plan->id ?>">
                        <input type="hidden" name="coupon_code" id="hidden_coupon_code">
                        
                        <!-- Payment Methods Grid -->
                        <div class="row g-3 mb-4">
                            <div class="col-4">
                                <input type="radio" class="btn-check" name="billing_type" id="method_pix" value="PIX" checked autocomplete="off">
                                <label class="payment-card d-flex flex-column align-items-center justify-content-center p-3 rounded-4 border w-100 h-100 position-relative" for="method_pix">
                                    <i class="fas fa-qrcode fa-2x mb-2 text-primary"></i>
                                    <span class="fw-bold small">PIX</span>
                                    <div class="check-icon position-absolute top-0 end-0 m-2 text-success d-none">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </label>
                            </div>
                            <div class="col-4">
                                <input type="radio" class="btn-check" name="billing_type" id="method_boleto" value="BOLETO" autocomplete="off">
                                <label class="payment-card d-flex flex-column align-items-center justify-content-center p-3 rounded-4 border w-100 h-100 position-relative" for="method_boleto">
                                    <i class="fas fa-barcode fa-2x mb-2 text-secondary"></i>
                                    <span class="fw-bold small">Boleto</span>
                                    <div class="check-icon position-absolute top-0 end-0 m-2 text-success d-none">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </label>
                            </div>
                            <div class="col-4">
                                <input type="radio" class="btn-check" name="billing_type" id="method_cc" value="CREDIT_CARD" autocomplete="off">
                                <label class="payment-card d-flex flex-column align-items-center justify-content-center p-3 rounded-4 border w-100 h-100 position-relative" for="method_cc">
                                    <i class="fas fa-credit-card fa-2x mb-2 text-warning"></i>
                                    <span class="fw-bold small text-center lh-sm">Cartão</span>
                                    <div class="check-icon position-absolute top-0 end-0 m-2 text-success d-none">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <style>
                            .payment-card {
                                cursor: pointer;
                                transition: all 0.2s ease;
                                background: #fff;
                                border: 2px solid #e9ecef !important;
                                color: #6c757d;
                            }
                            .payment-card:hover {
                                border-color: var(--bs-primary) !important;
                                background-color: var(--bs-light);
                            }
                            .btn-check:checked + .payment-card {
                                border-color: var(--bs-primary) !important;
                                background-color: rgba(var(--primary-rgb), 0.05); /* Tint color */
                                color: var(--bs-primary);
                                box-shadow: 0 4px 12px rgba(var(--primary-rgb), 0.15);
                            }
                            .btn-check:checked + .payment-card i.text-primary,
                            .btn-check:checked + .payment-card i.text-secondary,
                            .btn-check:checked + .payment-card i.text-warning {
                                color: var(--bs-primary) !important;
                            }
                            .btn-check:checked + .payment-card .check-icon {
                                display: block !important;
                            }
                        </style>
                        
                        <!-- Content Areas -->
                        <div class="tab-content" id="pills-tabContent">
                            
                            <!-- PIX Info -->
                            <div class="payment-info" id="info_pix">
                                <div class="alert alert-info border-0 rounded-4">
                                    <i class="fas fa-bolt me-2"></i> Pagamento aprovado na hora. Liberação imediata!
                                </div>
                            </div>

                            <!-- Boleto Info -->
                            <div class="payment-info d-none" id="info_boleto">
                                <div class="alert alert-warning border-0 rounded-4">
                                    <i class="fas fa-clock me-2"></i> Pode levar até 1 dia útil para compensar.
                                </div>
                            </div>
                            
                            <!-- Credit Card Info (Redirection) -->
                            <div class="payment-info d-none" id="info_cc">
                                <div class="alert alert-success border-0 rounded-4">
                                    <h6 class="alert-heading fw-bold"><i class="fas fa-shield-alt me-2"></i>Ambiente Seguro</h6>
                                    <p class="mb-0">
                                        Para sua segurança, você será redirecionado para a página de pagamento criptografada do nosso parceiro <strong>Asaas</strong>.
                                        Lá você poderá inserir os dados do seu cartão com total segurança.
                                    </p>
                                </div>
                            </div>

                        </div>
                        
                        <hr class="my-4">
                        
                        <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold shadow-sm fs-5">
                            Confirmar Assinatura <i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Layout & Tabs Logic ---
    const radios = document.querySelectorAll('input[name="billing_type"]');
    const pixInfo = document.getElementById('info_pix');
    const boletoInfo = document.getElementById('info_boleto');
    const ccInfo = document.getElementById('info_cc');
    const form = document.getElementById('paymentForm');
    const btn = form.querySelector('button[type="submit"]');

    radios.forEach(radio => {
        radio.addEventListener('change', function() {
            pixInfo.classList.add('d-none');
            boletoInfo.classList.add('d-none');
            ccInfo.classList.add('d-none');
            
            if(this.value === 'PIX') pixInfo.classList.remove('d-none');
            if(this.value === 'BOLETO') boletoInfo.classList.remove('d-none');
            if(this.value === 'CREDIT_CARD') ccInfo.classList.remove('d-none');
        });
    });

    // --- Masks ---
    // --- Masks & Zip Code Logic Removed ---
    // Since we redirect to Asaas, we don't handle sensitive data locally.

    form.addEventListener('submit', function(e) {
        // e.preventDefault();
        
        // Unmask before submit?
        // $('input[name="cc_phone"]').unmask();
        // But backend expects clean phone usually.
        // Let's assume Backend cleans it (PaymentService line 120 does replace non-digits).
        
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Processando...';
    });
    
    // --- Coupon Logic ---
    const applyCouponBtn = document.getElementById('apply_coupon_btn');
    const couponInput = document.getElementById('coupon_code_input');
    const couponFeedback = document.getElementById('coupon_feedback');
    const discountRow = document.getElementById('discount_row');
    const discountDisplay = document.getElementById('discount_display');
    const totalDisplay = document.getElementById('total_display');
    const hiddenCoupon = document.getElementById('hidden_coupon_code');
    
    // Use PHP to inject original price safely
    const originalPrice = <?= $plan->preco_mensal ?>;
    
    applyCouponBtn.addEventListener('click', function() {
        const code = couponInput.value.trim();
        if (!code) return;
        
        // Reset feedback
        couponFeedback.className = 'small mt-1 fw-bold';
        couponFeedback.textContent = 'Verificando...';
        couponFeedback.classList.remove('text-success', 'text-danger');
        applyCouponBtn.disabled = true;
        
        // AJAX Request
        fetch('<?= site_url("checkout/validate-coupon") ?>?plan_id=<?= $plan->id ?>&code=' + encodeURIComponent(code))
            .then(response => response.json())
            .then(data => {
                applyCouponBtn.disabled = false;
                
                if (data.valid) {
                    // Success
                    couponFeedback.textContent = 'Cupom aplicado com sucesso!';
                    couponFeedback.classList.add('text-success');
                    couponInput.classList.remove('is-invalid');
                    couponInput.classList.add('is-valid');
                    
                    // Update Values
                    const discountVal = parseFloat(data.discount_amount);
                    const finalVal = parseFloat(data.final_value);
                    
                    discountDisplay.textContent = '- R$ ' + discountVal.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                    totalDisplay.textContent = 'R$ ' + finalVal.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                    
                    discountRow.classList.remove('d-none');
                    hiddenCoupon.value = code;
                    
                } else {
                    // Error
                    couponFeedback.textContent = data.message || 'Cupom inválido.';
                    couponFeedback.classList.add('text-danger');
                    couponInput.classList.add('is-invalid');
                    
                    // Reset Values
                    discountRow.classList.add('d-none');
                    totalDisplay.textContent = 'R$ ' + originalPrice.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                    hiddenCoupon.value = '';
                }
            })
            .catch(err => {
                console.error(err);
                applyCouponBtn.disabled = false;
                couponFeedback.textContent = 'Erro ao validar cupom.';
                couponFeedback.classList.add('text-danger');
            });
    });
});
</script>
<?= $this->endSection() ?>
