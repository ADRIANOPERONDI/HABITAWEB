<?= $this->extend('Layouts/master') ?>

<?= $this->section('title') ?>Minha Assinatura<?= $this->endSection() ?>
<?= $this->section('page_title') ?>Minha Assinatura<?= $this->endSection() ?>

<?= $this->section('content') ?>
<div class="row g-4">
    <!-- Status Atual -->
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-4">
                    <div>
                        <h5 class="fw-bold mb-1">Plano Atual</h5>
                        <div class="text-muted small">Status da sua conta</div>
                    </div>
                    <?php if(isset($lastTransaction) && $lastTransaction): ?>
                        <span class="badge bg-warning text-dark px-3 py-2 rounded-pill">Pagamento Pendente</span>
                    <?php elseif($subscription && in_array(strtoupper($subscription->status), ['ATIVA', 'ACTIVE'])): ?>
                        <span class="badge bg-success text-white px-3 py-2 rounded-pill">Ativo</span>
                    <?php else: ?>
                        <span class="badge bg-danger text-white px-3 py-2 rounded-pill">Inativo</span>
                    <?php endif; ?>
                </div>

                <?php if(isset($pendingSubscription) && $pendingSubscription): ?>
                <?php $openInvoiceStatus = isset($lastTransaction->status) ? $lastTransaction->status : $pendingSubscription->status; ?>
                <div class="alert alert-warning border-0 shadow-sm mb-4">
                    <div class="d-flex align-items-start">
                        <i class="fa-solid fa-clock fa-2x me-3 opacity-50 mt-1"></i>
                        <div class="w-100">
                            <h6 class="fw-bold mb-1">Fatura em Aberto (<?= lang('Payments.status_' . strtolower($openInvoiceStatus)) ?>)</h6>
                            <p class="small mb-3">
                                <?php if(isset($pendingSubscription->custom_pending_msg) && $pendingSubscription->custom_pending_msg): ?>
                                    <?= $pendingSubscription->custom_pending_msg ?>
                                <?php else: ?>
                                    Você tem uma assinatura do plano <strong><?= esc($pendingPlan ? $pendingPlan->nome : 'Novo Plano') ?></strong> aguardando confirmação.
                                <?php endif; ?>
                            </p>
                            
                            <?php 
                                $invoiceUrl = '#';
                                $bankSlipUrl = null;
                                $pixPayload = null;
                                $pixImage = null;
                                $paymentMethod = $lastTransaction->payment_method ?? $pendingSubscription->payment_method ?? null;
                                $paymentMethodLabels = [
                                    'PIX' => 'Pix',
                                    'BOLETO' => 'Boleto',
                                    'CREDIT_CARD' => 'Cartão de crédito',
                                ];
                                $paymentMethodIcons = [
                                    'PIX' => 'fa-qrcode',
                                    'BOLETO' => 'fa-barcode',
                                    'CREDIT_CARD' => 'fa-credit-card',
                                ];
                                if(isset($lastTransaction)) {
                                    $invoiceUrl = $lastTransaction->invoice_url ?? '#';
                                    
                                    // Fallback para metadados se a coluna estiver vazia
                                    if (!empty($lastTransaction->metadata)) {
                                        $meta = is_string($lastTransaction->metadata) ? json_decode($lastTransaction->metadata, true) : (array)$lastTransaction->metadata;
                                        $invoiceUrl = ($invoiceUrl === '#') ? ($meta['invoice_url'] ?? '#') : $invoiceUrl;
                                        $bankSlipUrl = $meta['bank_slip_url'] ?? null;
                                        $pixPayload = $meta['qr_code'] ?? null;
                                        $pixImage = $meta['qr_code_image'] ?? null;
                                    }
                                }
                                $paymentMethodKey = strtoupper((string) $paymentMethod);
                                $paymentMethodLabel = $paymentMethodLabels[$paymentMethodKey] ?? 'Não definida';
                                $paymentMethodIcon = $paymentMethodIcons[$paymentMethodKey] ?? 'fa-money-bill';
                            ?>

                            <?php if(isset($lastTransaction) && $lastTransaction): ?>
                            <div class="bg-white rounded-3 p-3 mb-3 border">
                                <div class="row g-3 align-items-center">
                                    <div class="col-sm-4">
                                        <div class="text-muted small">Forma de pagamento</div>
                                        <div class="fw-bold"><i class="fas <?= esc($paymentMethodIcon) ?> me-1"></i> <?= esc($paymentMethodLabel) ?></div>
                                    </div>
                                    <div class="col-sm-4">
                                        <div class="text-muted small">Valor</div>
                                        <div class="fw-bold">R$ <?= number_format((float) ($lastTransaction->amount ?? 0), 2, ',', '.') ?></div>
                                    </div>
                                    <div class="col-sm-4">
                                        <div class="text-muted small">Vencimento</div>
                                        <div class="fw-bold"><?= !empty($lastTransaction->due_date) ? date('d/m/Y', strtotime($lastTransaction->due_date)) : '-' ?></div>
                                    </div>
                                </div>

                                <?php if($paymentMethodKey === 'PIX' && $pixPayload): ?>
                                    <div class="mt-3">
                                        <?php if($pixImage): ?>
                                            <img src="data:image/png;base64,<?= esc($pixImage) ?>" alt="QR Code Pix" class="rounded bg-white border p-1 mb-2" style="max-width: 150px;">
                                        <?php endif; ?>
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control" value="<?= esc($pixPayload) ?>" id="pendingPixCopy" readonly>
                                            <button class="btn btn-outline-primary" type="button" id="btn-copy-pending-pix">
                                                <i class="fas fa-copy"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php elseif($paymentMethodKey === 'BOLETO' && $bankSlipUrl): ?>
                                    <div class="mt-3">
                                        <a href="<?= esc($bankSlipUrl) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-file-pdf me-1"></i> Abrir boleto
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <div class="d-flex flex-wrap gap-2">
                                <a href="<?= esc($invoiceUrl) ?>" target="_blank" class="btn btn-dark <?= $invoiceUrl === '#' ? 'disabled' : '' ?>">
                                    <i class="fas fa-external-link-alt me-2"></i> Pagar agora
                                </a>

                                <?php if(isset($lastTransaction) && $lastTransaction): ?>
                                    <button type="button"
                                            class="btn btn-outline-primary btn-change-payment-method"
                                            data-action="<?= site_url('admin/subscription/payment-method/' . $lastTransaction->id) ?>"
                                            data-current="<?= esc($paymentMethodKey) ?>">
                                        <i class="fas fa-repeat me-2"></i> Alterar forma de pagamento
                                    </button>
                                <?php endif; ?>
                                
                                <form action="<?= site_url('admin/subscription/cancel/' . $pendingSubscription->id) ?>" method="POST" id="form-cancel-subscription">
                                    <?= csrf_field() ?>
                                     <button type="button" class="btn btn-outline-danger btn-cancel-subscription"><?= lang('Payments.confirm_cancel_btn') ?></button>
                                </form>
                            </div>

                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="p-3 bg-light rounded-3 d-flex align-items-center gap-4 mb-4">
                    <div class="bg-white p-3 rounded-circle shadow-sm text-primary">
                        <i class="fa-solid fa-crown fa-2x"></i>
                    </div>
                    <div>
                        <h3 class="fw-bold mb-0"><?= esc($plan ? $plan->nome : 'Gratuito / Sem Plano') ?></h3>
                        <div class="text-muted">
                            <?php if($plan): ?>
                                R$ <?= number_format($plan->preco_mensal, 2, ',', '.') ?> / mês
                            <?php else: ?>
                                Grátis
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold mb-3">Uso do Plano</h6>
                <div class="mb-2 d-flex justify-content-between text-muted small">
                    <span>Imóveis Ativos</span>
                    <span>
                        <strong><?= $usage['active_properties'] ?></strong> 
                        / <?= $usage['is_unlimited'] ? '∞' : $usage['limit'] ?>
                    </span>
                </div>
                <?php 
                    $percent = 0;
                    if (!$usage['is_unlimited'] && $usage['limit'] > 0) {
                        $percent = ($usage['active_properties'] / $usage['limit']) * 100;
                    }
                ?>
                <div class="progress mb-4" style="height: 8px;">
                    <div class="progress-bar <?= $percent > 90 ? 'bg-danger' : 'bg-primary' ?>" role="progressbar" style="width: <?= $percent ?>%"></div>
                </div>

                <hr>

                <div class="d-flex justify-content-between text-muted small">
                    <div>Assinado em: <?= $subscription ? date('d/m/Y', strtotime($subscription->data_inicio)) : '-' ?></div>
                    <div>Renova em: <?= $subscription && $subscription->data_fim ? date('d/m/Y', strtotime($subscription->data_fim)) : 'Indeterminado' ?></div>
                </div>
            </div>
        </div>

        <h4 class="fw-bold mb-3">Mudar de Plano</h4>
        <div class="row g-3">
            <?php foreach($allPlans as $p): ?>
            <div class="col-md-4">
                <div class="card h-100 border-0 shadow-sm <?= ($plan && $plan->id == $p->id) ? 'border border-primary border-2' : '' ?>">
                    <div class="card-body p-4 text-center d-flex flex-column">
                        <?php if($plan && $plan->id == $p->id): ?>
                            <div class="position-absolute top-0 start-50 translate-middle badge bg-primary">Atual</div>
                        <?php endif; ?>
                        
                        <h5 class="fw-bold mb-3"><?= esc($p->nome) ?></h5>
                        <div class="mb-4">
                            <span class="h3 fw-bold">R$ <?= number_format($p->preco_mensal, 2, ',', '.') ?></span><span class="text-muted">/mês</span>
                        </div>
                        
                        <ul class="list-unstyled text-start mb-4 small text-muted flex-grow-1">
                            <li class="mb-2"><i class="fa-solid fa-check text-success me-2"></i> 
                                <?= $p->limite_imoveis_ativos === null ? 'Imóveis Ilimitados' : $p->limite_imoveis_ativos . ' Imóveis Ativos' ?>
                            </li>
                            <li class="mb-2"><i class="fa-solid fa-check text-success me-2"></i> 
                                <?= $p->limite_fotos_por_imovel ?> Fotos por Imóvel
                            </li>
                            <li class="mb-2"><i class="fa-solid fa-check text-success me-2"></i> 
                                <?= $p->destaques_mensais ?> Destaques/mês
                            </li>
                        </ul>

                        <?php if($plan && $plan->id == $p->id): ?>
                            <button class="btn btn-outline-primary w-100 rounded-pill disabled shadow-none">Plano Atual</button>
                        <?php else: ?>
                            <button type="button" 
                                    class="btn btn-primary w-100 rounded-pill btn-change-plan shadow-sm" 
                                    data-id="<?= $p->id ?>" 
                                    data-name="<?= esc($p->nome) ?>"
                                    <?= ($plan && $plan->preco_mensal > $p->preco_mensal) ? 'data-is-downgrade="true"' : '' ?>>
                                <i class="fas fa-arrow-right me-1"></i> Mudar de Plano
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>

    <!-- Sidebar Ajuda (Opcional) -->
    <div class="col-12 col-lg-4">
        <div class="card bg-primary text-white border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-2">Precisa de mais?</h5>
                <p class="small text-white-50 mb-4">Entre em contato com nossa equipe comercial para planos empresariais personalizados.</p>
                <button class="btn btn-light text-primary w-100 fw-bold">Falar com Consultor</button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
    $(document).ready(function() {
        console.log('Subscription scripts loaded');

        $(document).on('click', '.btn-change-plan', function() {
            const planId = $(this).data('id');
            const planName = $(this).data('name');
            const isDowngrade = $(this).data('is-downgrade');
            const upgradeUrl = '<?= site_url('admin/subscription/upgrade/') ?>' + planId;
            const previewUrl = '<?= site_url('admin/subscription/preview-upgrade/') ?>' + planId;

            console.log('Change plan clicked:', {planId, planName, isDowngrade});

            // Mostrar loading inicial
            Swal.fire({
                title: 'Processando...',
                text: 'Calculando detalhes da troca de plano',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            // Buscar preview do upgrade
            $.get(previewUrl, function(data) {
                console.log('Preview data received:', data);

                if (data.is_downgrade) {
                    Swal.fire({
                        title: 'Downgrade Indisponível',
                        html: `Você está tentando mudar para o plano <strong>${data.new_plan_name}</strong>, que possui um valor inferior ao seu plano atual.<br><br>Para realizar um downgrade, você deve primeiro <strong>cancelar sua assinatura atual</strong> e, após o término do período ou cancelamento, contratar o novo plano desejado.`,
                        icon: 'warning',
                        confirmButtonText: 'Entendi',
                        confirmButtonColor: '#3085d6'
                    });
                    return;
                }
                
                let config = {
                    title: 'Confirmar Troca de Plano',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Sim, Alterar Plano',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                };

                if (data.is_upgrade && data.pro_rata > 0) {
                    config.html = `
                        <div class="text-start">
                            <p>Você está fazendo um upgrade para o <strong>${data.new_plan_name}</strong>.</p>
                            <div class="alert alert-info border-0 py-3">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Cobrança Proporcional (Pró-rata):</strong><br>
                                Será gerada uma cobrança única de <strong>R$ ${data.formatted_pro_rata}</strong> relativa aos dias restantes até o próximo vencimento.
                            </div>
                            <p class="small text-muted">As próximas mensalidades serão no novo valor integral de R$ ${data.new_price.toLocaleString('pt-BR', {minimumFractionDigits: 2})}.</p>
                        </div>
                    `;
                } else {
                    config.text = `Deseja mudar para o plano ${data.new_plan_name}?`;
                }

                Swal.fire(config).then((result) => {
                    if (result.isConfirmed) {
                        openPaymentMethodModal({
                            title: 'Como deseja pagar?',
                            text: 'Escolha a forma de pagamento para concluir a troca de plano.',
                            action: upgradeUrl,
                            current: 'PIX'
                        });
                    }
                });
            }).fail(function(xhr) {
                console.error('Preview error:', xhr);
                Swal.fire('Erro!', 'Não foi possível calcular a troca de plano.', 'error');
            });
        });

        $(document).on('click', '.btn-change-payment-method', function() {
            openPaymentMethodModal({
                title: 'Alterar forma de pagamento',
                text: 'Vamos cancelar a cobrança pendente atual e gerar uma nova no método escolhido.',
                action: $(this).data('action'),
                current: $(this).data('current') || 'PIX'
            });
        });

        $('#btn-copy-pending-pix').click(function() {
            const input = document.getElementById('pendingPixCopy');
            if (!input) return;

            input.select();
            input.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(input.value);
            Swal.fire('Copiado!', 'Código Pix copiado para a área de transferência.', 'success');
        });

        $('.btn-cancel-subscription').click(function(e) {
            e.preventDefault();
            Swal.fire({
                title: '<?= lang('Payments.confirm_cancel_title') ?>',
                text: '<?= lang('Payments.confirm_cancel_text') ?>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#344767',
                confirmButtonText: '<?= lang('Payments.confirm_cancel_btn') ?>',
                cancelButtonText: '<?= lang('Payments.cancel_btn') ?>'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#form-cancel-subscription').submit();
                }
            });
        });

        function openPaymentMethodModal(options) {
            const current = String(options.current || 'PIX').toUpperCase();
            const checked = (method) => current === method ? 'checked' : '';

            Swal.fire({
                title: options.title,
                html: `
                    <p class="text-muted small mb-3">${options.text}</p>
                    <div class="text-start d-grid gap-2">
                        <label class="border rounded-3 p-3 d-flex align-items-center gap-3 cursor-pointer">
                            <input type="radio" name="swal_billing_type" value="PIX" ${checked('PIX')}>
                            <span><i class="fas fa-qrcode me-2 text-primary"></i><strong>Pix</strong><br><small class="text-muted">Aprovação rápida com QR Code ou copia e cola.</small></span>
                        </label>
                        <label class="border rounded-3 p-3 d-flex align-items-center gap-3 cursor-pointer">
                            <input type="radio" name="swal_billing_type" value="BOLETO" ${checked('BOLETO')}>
                            <span><i class="fas fa-barcode me-2 text-secondary"></i><strong>Boleto</strong><br><small class="text-muted">Pagamento por boleto bancário.</small></span>
                        </label>
                        <label class="border rounded-3 p-3 d-flex align-items-center gap-3 cursor-pointer">
                            <input type="radio" name="swal_billing_type" value="CREDIT_CARD" ${checked('CREDIT_CARD')}>
                            <span><i class="fas fa-credit-card me-2 text-warning"></i><strong>Cartão de crédito</strong><br><small class="text-muted">Você será levado para a página segura do Asaas.</small></span>
                        </label>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Continuar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#344767',
                preConfirm: () => {
                    const selected = document.querySelector('input[name="swal_billing_type"]:checked');
                    return selected ? selected.value : null;
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    submitBillingForm(options.action, result.value);
                }
            });
        }

        function submitBillingForm(action, billingType) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = action;
            form.innerHTML = `
                <?= csrf_field() ?>
                <input type="hidden" name="billing_type" value="${billingType}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
</script>
<?= $this->endSection() ?>
